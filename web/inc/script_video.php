<?php
/**
 * PTMD — Script Video Helpers (inc/script_video.php)
 *
 * Domain helpers for the "Create From Script" workflow:
 *  - Script normalization / validation
 *  - Scene parsing and duration estimation
 *  - AI scene-plan generation adapter
 *  - FFmpeg render orchestration (per-scene + final assembly)
 *  - Job lifecycle management
 *
 * Requires inc/bootstrap.php and inc/video_processor.php to be loaded first.
 *
 * Security: never pass unsanitized user data to shell — always use escapeshellarg().
 */

// ---------------------------------------------------------------------------
// Limits & constants
// ---------------------------------------------------------------------------

define('SV_MAX_SCRIPT_CHARS', 20000);
define('SV_MAX_SCENES',       30);
define('SV_MIN_SCENE_DUR',    1.0);    // seconds
define('SV_MAX_SCENE_DUR',    120.0);  // seconds
define('SV_DEFAULT_SCENE_DUR', 5.0);  // seconds

// ---------------------------------------------------------------------------
// Script normalization
// ---------------------------------------------------------------------------

/** Strip null bytes, normalise line endings, and trim outer whitespace. */
function sv_normalize_script(string $text): string
{
    $text = str_replace("\r\n", "\n", $text);
    $text = str_replace("\r",   "\n", $text);
    $text = str_replace("\x00", '',   $text);
    return trim($text);
}

// ---------------------------------------------------------------------------
// Scene parsing
//
// Splits a plain-text script on one-or-more blank lines so each paragraph
// becomes one scene.  Duration is estimated from word count (~150 wpm).
// ---------------------------------------------------------------------------

/**
 * @param  string $script          Normalized script text.
 * @param  float  $defaultDuration Fallback seconds per scene.
 * @return array  Array of scene-data arrays compatible with sv_create_job().
 */
function sv_parse_scenes(string $script, float $defaultDuration = SV_DEFAULT_SCENE_DUR): array
{
    $script = sv_normalize_script($script);
    if ($script === '') {
        return [];
    }

    $paragraphs = preg_split('/\n{2,}/', $script);
    $scenes     = [];
    $order      = 1;

    foreach ($paragraphs as $para) {
        $text = trim($para);
        if ($text === '') {
            continue;
        }

        // ~150 words per minute → 2.5 words per second
        $wordCount = str_word_count($text);
        $estimated = $wordCount > 0 ? round($wordCount / 2.5, 1) : $defaultDuration;
        $duration  = max(SV_MIN_SCENE_DUR, min(SV_MAX_SCENE_DUR, $estimated));

        $scenes[] = [
            'scene_order'    => $order,
            'narration_text' => $text,
            'visual_prompt'  => null,
            'asset_path'     => null,
            'duration_sec'   => $duration,
            'overlay_text'   => null,
        ];

        $order++;
        if ($order > SV_MAX_SCENES + 1) {
            break;
        }
    }

    return $scenes;
}

// ---------------------------------------------------------------------------
// Input validation
// ---------------------------------------------------------------------------

/**
 * Validate the incoming create-job payload.
 *
 * @param  array $data  Associative array with keys: input_mode, title,
 *                      script_source (manual), ai_prompt (ai).
 * @return string[]     Array of error strings; empty = valid.
 */
function sv_validate_job(array $data): array
{
    $errors = [];

    $mode = trim((string) ($data['input_mode'] ?? ''));
    if (!in_array($mode, ['ai', 'manual'], true)) {
        $errors[] = 'Invalid input mode.';
        return $errors;
    }

    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        $errors[] = 'Title is required.';
    } elseif (mb_strlen($title) > 255) {
        $errors[] = 'Title must be 255 characters or fewer.';
    }

    if ($mode === 'manual') {
        $script = trim((string) ($data['script_source'] ?? ''));
        if ($script === '') {
            $errors[] = 'Script text is required for manual mode.';
        } elseif (mb_strlen($script) > SV_MAX_SCRIPT_CHARS) {
            $errors[] = 'Script exceeds maximum length (' . number_format(SV_MAX_SCRIPT_CHARS) . ' characters).';
        }
    } else {
        $prompt = trim((string) ($data['ai_prompt'] ?? ''));
        if ($prompt === '') {
            $errors[] = 'AI prompt / topic is required for AI mode.';
        } elseif (mb_strlen($prompt) > 2000) {
            $errors[] = 'AI prompt must be 2000 characters or fewer.';
        }
    }

    return $errors;
}

// ---------------------------------------------------------------------------
// Job + scene persistence
// ---------------------------------------------------------------------------

/**
 * Insert a new script_video_jobs row and its associated scene rows.
 *
 * @param  PDO   $pdo     Active PDO connection.
 * @param  array $data    Validated payload (input_mode, title, script_source|ai_prompt,
 *                        episode_id, scenes).
 * @param  int   $userId  Admin user ID (0 for anonymous, stored as NULL).
 * @return int            New job ID, or 0 on failure.
 */
function sv_create_job(PDO $pdo, array $data, int $userId): int
{
    $mode      = $data['input_mode'];
    $title     = mb_substr(trim((string) ($data['title'] ?? 'Untitled Script Video')), 0, 255);
    $scriptSrc = $mode === 'manual' ? trim((string) ($data['script_source'] ?? '')) : null;
    $aiPrompt  = $mode === 'ai'     ? trim((string) ($data['ai_prompt']      ?? '')) : null;
    $episodeId = isset($data['episode_id']) && (int) $data['episode_id'] > 0
                 ? (int) $data['episode_id'] : null;
    $scenes    = $data['scenes'] ?? [];

    $stmt = $pdo->prepare(
        'INSERT INTO script_video_jobs
         (input_mode, title, script_source, ai_prompt, episode_id, status, progress, created_by, created_at, updated_at)
         VALUES (:mode, :title, :script, :ai_prompt, :episode_id, "pending", 0, :user, NOW(), NOW())'
    );
    $stmt->execute([
        'mode'       => $mode,
        'title'      => $title,
        'script'     => $scriptSrc,
        'ai_prompt'  => $aiPrompt,
        'episode_id' => $episodeId,
        'user'       => $userId > 0 ? $userId : null,
    ]);

    $jobId = (int) $pdo->lastInsertId();
    if ($jobId === 0) {
        return 0;
    }

    if ($scenes) {
        $sceneStmt = $pdo->prepare(
            'INSERT INTO script_video_scenes
             (job_id, scene_order, narration_text, visual_prompt, asset_path, duration_sec, overlay_text, status, created_at, updated_at)
             VALUES (:job_id, :order, :narration, :visual, :asset, :duration, :overlay, "pending", NOW(), NOW())'
        );
        foreach ($scenes as $s) {
            $sceneStmt->execute([
                'job_id'   => $jobId,
                'order'    => (int) ($s['scene_order'] ?? 1),
                'narration'=> mb_substr((string) ($s['narration_text'] ?? ''), 0, 65535),
                'visual'   => isset($s['visual_prompt']) ? mb_substr((string) $s['visual_prompt'], 0, 65535) : null,
                'asset'    => isset($s['asset_path'])    ? mb_substr((string) $s['asset_path'],    0, 255)   : null,
                'duration' => min(SV_MAX_SCENE_DUR, max(SV_MIN_SCENE_DUR, (float) ($s['duration_sec'] ?? SV_DEFAULT_SCENE_DUR))),
                'overlay'  => isset($s['overlay_text'])  ? mb_substr((string) $s['overlay_text'],  0, 500)   : null,
            ]);
        }
    }

    return $jobId;
}

// ---------------------------------------------------------------------------
// FFmpeg — scene rendering
// ---------------------------------------------------------------------------

/**
 * Escape a string for use inside an FFmpeg drawtext filter value.
 * Only escapes characters that have special meaning in filter strings.
 */
function sv_drawtext_escape(string $text): string
{
    // Order matters: escape backslash first
    $text = str_replace('\\', '\\\\',   $text);
    $text = str_replace("'",  "'\\\\''", $text);
    $text = str_replace(':',  '\\:',    $text);
    $text = str_replace("\n", '\n',     $text);
    // Truncate to reasonable display length
    if (mb_strlen($text) > 300) {
        $text = mb_substr($text, 0, 300) . '...';
    }
    return $text;
}

/**
 * Render a single scene to an MP4 using FFmpeg's lavfi colour source
 * and the drawtext filter (dark-background + white centred text).
 *
 * Returns ['ok' => bool, 'output' => abs_path, 'error' => msg, 'command' => cmd].
 */
function sv_render_scene(array $scene, string $outputDir): array
{
    if (!is_dir($outputDir)) {
        if (!mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            return ['ok' => false, 'error' => 'Cannot create scene output directory.'];
        }
    }

    $duration = max(SV_MIN_SCENE_DUR, (float) ($scene['duration_sec'] ?? SV_DEFAULT_SCENE_DUR));
    $outFile  = 'svscene_' . ((int) $scene['id']) . '_' . time() . '.mp4';
    $outPath  = rtrim($outputDir, '/') . '/' . $outFile;

    $text      = sv_drawtext_escape((string) ($scene['narration_text'] ?? ''));
    $colorSpec = sprintf('color=c=0x1a1a2e:size=1920x1080:rate=25:d=%s', number_format($duration, 3, '.', ''));

    $drawFilter = "drawtext=text='{$text}'"
        . ':font=DejaVuSans'
        . ':fontsize=44'
        . ':fontcolor=white'
        . ':x=(w-tw)/2'
        . ':y=(h-th)/2'
        . ':line_spacing=14'
        . ':expansion=none';

    $filterComplex = "[0:v]{$drawFilter}[v]";

    $cmd = sprintf(
        '%s -y -f lavfi -i %s -filter_complex %s -map "[v]" -c:v libx264 -crf 22 -preset fast -movflags +faststart %s 2>&1',
        ffmpeg_bin(),
        escapeshellarg($colorSpec),
        escapeshellarg($filterComplex),
        escapeshellarg($outPath)
    );

    exec($cmd, $cmdOutput, $returnCode);

    if ($returnCode !== 0) {
        return [
            'ok'      => false,
            'error'   => 'FFmpeg scene render failed (exit ' . $returnCode . ')',
            'command' => $cmd,
        ];
    }

    return [
        'ok'      => true,
        'output'  => $outPath,
        'command' => $cmd,
    ];
}

// ---------------------------------------------------------------------------
// FFmpeg — concat all scene clips into one final video
// ---------------------------------------------------------------------------

/**
 * Concatenate ordered scene clip paths into a single output MP4.
 *
 * Returns ['ok' => bool, 'output' => abs_path, 'error' => msg].
 */
function sv_concat_scenes(array $scenePaths, string $outputPath): array
{
    if (empty($scenePaths)) {
        return ['ok' => false, 'error' => 'No scene clips to concatenate.'];
    }

    $outDir = dirname($outputPath);
    if (!is_dir($outDir)) {
        if (!mkdir($outDir, 0755, true) && !is_dir($outDir)) {
            return ['ok' => false, 'error' => 'Cannot create output directory.'];
        }
    }

    // Write a temporary concat-demuxer list file
    $listFile = sys_get_temp_dir() . '/sv_concat_' . getmypid() . '_' . time() . '.txt';
    $lines    = [];
    foreach ($scenePaths as $path) {
        if (!is_file($path)) {
            return ['ok' => false, 'error' => 'Scene clip not found: ' . basename($path)];
        }
        // Use absolute paths; no shell expansion — just quoted by the demuxer
        $lines[] = "file '" . str_replace("'", "'\\''", $path) . "'";
    }
    file_put_contents($listFile, implode("\n", $lines) . "\n");

    $cmd = sprintf(
        '%s -y -f concat -safe 0 -i %s -c:v libx264 -crf 20 -preset fast -movflags +faststart %s 2>&1',
        ffmpeg_bin(),
        escapeshellarg($listFile),
        escapeshellarg($outputPath)
    );

    exec($cmd, $cmdOutput, $returnCode);
    @unlink($listFile);

    if ($returnCode !== 0) {
        return [
            'ok'    => false,
            'error' => 'FFmpeg concat failed (exit ' . $returnCode . ')',
        ];
    }

    return [
        'ok'     => true,
        'output' => $outputPath,
    ];
}

// ---------------------------------------------------------------------------
// Rendering pipeline orchestration
// ---------------------------------------------------------------------------

/**
 * Process all scenes for a script-video job and produce the final output.
 * Updates script_video_jobs.status / progress / phase and
 * script_video_scenes.status / output_path throughout.
 *
 * Called synchronously for small jobs; large jobs leave remaining
 * scenes queued for a background process.
 */
function sv_process_job(PDO $pdo, int $jobId): void
{
    $jobStmt = $pdo->prepare('SELECT * FROM script_video_jobs WHERE id = :id LIMIT 1');
    $jobStmt->execute(['id' => $jobId]);
    $job = $jobStmt->fetch();

    if (!$job || !in_array($job['status'], ['pending', 'processing'], true)) {
        return;
    }

    $updateJob = static function (
        string $status,
        int    $progress,
        string $phase = '',
        string $error = ''
    ) use ($pdo, $jobId): void {
        $pdo->prepare(
            'UPDATE script_video_jobs
             SET status=:s, progress=:p, phase=:ph, error_message=:e, updated_at=NOW()
             WHERE id=:id'
        )->execute([
            's'  => $status,
            'p'  => $progress,
            'ph' => $phase !== '' ? $phase : null,
            'e'  => $error !== '' ? $error : null,
            'id' => $jobId,
        ]);
    };

    $updateJob('processing', 5, 'Loading scenes');

    $sceneStmt = $pdo->prepare(
        'SELECT * FROM script_video_scenes WHERE job_id = :jid ORDER BY scene_order ASC'
    );
    $sceneStmt->execute(['jid' => $jobId]);
    $sceneRows = $sceneStmt->fetchAll();

    if (empty($sceneRows)) {
        $updateJob('failed', 0, '', 'No scenes found for this job.');
        return;
    }

    $uploadBase  = rtrim($GLOBALS['config']['upload_dir'], '/');
    $sceneOutDir = $uploadBase . '/script-video/scenes';
    $finalOutDir = $uploadBase . '/script-video';

    $scenePaths = [];
    $total      = count($sceneRows);
    $done       = 0;

    foreach ($sceneRows as $scene) {
        $progressPct = (int) (5 + (($done / $total) * 75));
        $updateJob('processing', $progressPct, 'Rendering scene ' . $scene['scene_order'] . ' of ' . $total);

        $pdo->prepare(
            'UPDATE script_video_scenes SET status="processing", updated_at=NOW() WHERE id=:id'
        )->execute(['id' => $scene['id']]);

        $result = sv_render_scene($scene, $sceneOutDir);

        if ($result['ok']) {
            $relPath = 'script-video/scenes/' . basename((string) $result['output']);
            $pdo->prepare(
                'UPDATE script_video_scenes SET status="done", output_path=:out, updated_at=NOW() WHERE id=:id'
            )->execute(['out' => $relPath, 'id' => $scene['id']]);
            $scenePaths[] = (string) $result['output'];
        } else {
            $pdo->prepare(
                'UPDATE script_video_scenes SET status="failed", error_message=:err, updated_at=NOW() WHERE id=:id'
            )->execute(['err' => $result['error'], 'id' => $scene['id']]);
            $updateJob('failed', $progressPct, 'Scene render failed', $result['error']);
            return;
        }

        $done++;
    }

    $updateJob('processing', 82, 'Assembling final video');

    $outFile = 'sv_job_' . $jobId . '_' . time() . '.mp4';
    $outAbs  = $finalOutDir . '/' . $outFile;
    $outRel  = 'script-video/' . $outFile;

    $concat = sv_concat_scenes($scenePaths, $outAbs);
    if (!$concat['ok']) {
        $updateJob('failed', 82, 'Assembly failed', $concat['error']);
        return;
    }

    $meta     = probe_video($outAbs);
    $duration = $meta['duration'] ?? null;

    $pdo->prepare(
        'UPDATE script_video_jobs
         SET status="completed", progress=100, phase="Done",
             output_path=:out, output_duration=:dur, updated_at=NOW()
         WHERE id=:id'
    )->execute(['out' => $outRel, 'dur' => $duration, 'id' => $jobId]);
}

// ---------------------------------------------------------------------------
// AI scene-plan generation
// ---------------------------------------------------------------------------

/**
 * Call OpenAI to generate a structured scene breakdown from a prompt.
 *
 * Returns:
 *   ['ok' => true,  'scenes' => [...], 'raw' => string, 'model' => string,
 *    'prompt_tokens' => int, 'response_tokens' => int]
 *   ['ok' => false, 'error' => string]
 */
function sv_ai_generate_scenes(string $prompt, string $title = ''): array
{
    $systemPrompt = ptmd_ai_system_prompt();
    $titleClause  = $title !== '' ? "The video title is: \"{$title}\".\n" : '';

    $userPrompt = "Generate a scene-by-scene breakdown for a short documentary video.\n"
        . $titleClause
        . "Topic / brief: {$prompt}\n\n"
        . "Requirements:\n"
        . "- Create 4 to 8 scenes.\n"
        . "- Each scene has on-screen narration text (1-3 sentences).\n"
        . "- Suggest a duration in seconds appropriate for the text length (3-15 seconds).\n"
        . "- Provide a visual_prompt describing ideal footage or background for each scene.\n\n"
        . "Return ONLY valid JSON array (no markdown, no extra commentary).\n"
        . "Each item must have exactly these keys:\n"
        . "  narration_text  (string)\n"
        . "  visual_prompt   (string)\n"
        . "  duration_sec    (number, 3-15)\n";

    $result = openai_chat($systemPrompt, $userPrompt, 1600);

    if (!$result['ok']) {
        return ['ok' => false, 'error' => $result['error']];
    }

    $raw     = $result['text'];
    $decoded = sv_parse_json_response($raw);

    if (!is_array($decoded) || count($decoded) === 0) {
        return ['ok' => false, 'error' => 'AI did not return valid scene JSON. Try rephrasing your prompt.'];
    }

    $scenes = [];
    $order  = 1;

    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }
        $narration = trim((string) ($item['narration_text'] ?? ''));
        $visual    = trim((string) ($item['visual_prompt']  ?? ''));
        $dur       = max(
            SV_MIN_SCENE_DUR,
            min(SV_MAX_SCENE_DUR, (float) ($item['duration_sec'] ?? SV_DEFAULT_SCENE_DUR))
        );

        if ($narration === '') {
            continue;
        }

        $scenes[] = [
            'scene_order'    => $order,
            'narration_text' => $narration,
            'visual_prompt'  => $visual !== '' ? $visual : null,
            'asset_path'     => null,
            'duration_sec'   => $dur,
            'overlay_text'   => null,
        ];

        $order++;
        if ($order > SV_MAX_SCENES + 1) {
            break;
        }
    }

    if (empty($scenes)) {
        return ['ok' => false, 'error' => 'AI returned no valid scenes. Try a more detailed prompt.'];
    }

    return [
        'ok'              => true,
        'scenes'          => $scenes,
        'raw'             => $raw,
        'model'           => $result['model'],
        'prompt_tokens'   => $result['prompt_tokens'],
        'response_tokens' => $result['response_tokens'],
    ];
}

// ---------------------------------------------------------------------------
// JSON response parser (shared utility)
// ---------------------------------------------------------------------------

/**
 * Attempt to extract a JSON array from an AI text response.
 * Handles direct JSON, markdown code blocks, and embedded arrays.
 */
function sv_parse_json_response(string $text): array
{
    $trimmed = trim($text);
    if ($trimmed === '') {
        return [];
    }

    $decoded = json_decode($trimmed, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/```(?:json)?\s*(\[.*?\])\s*```/is', $trimmed, $m)) {
        $decoded = json_decode($m[1], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    $start = strpos($trimmed, '[');
    $end   = strrpos($trimmed, ']');
    if ($start !== false && $end !== false && $end > $start) {
        $decoded = json_decode(substr($trimmed, $start, $end - $start + 1), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

<?php
/**
 * PTMD — Video Processor Helpers (inc/video_processor.php)
 *
 * Wraps FFmpeg/FFprobe calls for:
 *  - Probing video duration/metadata
 *  - Extracting a clip (start → end time)
 *  - Compositing an overlay PNG onto a video
 *  - Adding a watermark image
 *  - Batch overlay processing via the DB queue
 *
 * All commands are logged to overlay_batch_items.ffmpeg_command for debugging.
 * NEVER pass unsanitized user input directly to shell — always use escapeshellarg().
 */

// ---------------------------------------------------------------------------
// FFmpeg / FFprobe binary resolution
// ---------------------------------------------------------------------------

function ffmpeg_bin(): string
{
    return escapeshellcmd(site_setting('ffmpeg_path',  $GLOBALS['config']['ffmpeg_path']  ?? 'ffmpeg'));
}

function ffprobe_bin(): string
{
    return escapeshellcmd(site_setting('ffprobe_path', $GLOBALS['config']['ffprobe_path'] ?? 'ffprobe'));
}

// ---------------------------------------------------------------------------
// Probe a video file for duration and basic metadata
// Returns array or null on failure.
// ---------------------------------------------------------------------------

function probe_video(string $absolutePath): ?array
{
    if (!is_file($absolutePath)) {
        return null;
    }

    $cmd = sprintf(
        '%s -v quiet -print_format json -show_format -show_streams %s 2>&1',
        ffprobe_bin(),
        escapeshellarg($absolutePath)
    );

    $output = shell_exec($cmd);
    if (!$output) {
        return null;
    }

    $data = json_decode($output, true);
    if (!is_array($data)) {
        return null;
    }

    $duration = (float) ($data['format']['duration'] ?? 0);
    $width    = 0;
    $height   = 0;

    foreach ($data['streams'] ?? [] as $stream) {
        if (($stream['codec_type'] ?? '') === 'video') {
            $width  = (int) ($stream['width']  ?? 0);
            $height = (int) ($stream['height'] ?? 0);
            break;
        }
    }

    return [
        'duration' => $duration,
        'width'    => $width,
        'height'   => $height,
        'format'   => $data['format']['format_name'] ?? '',
        'size'     => (int) ($data['format']['size'] ?? 0),
    ];
}

// ---------------------------------------------------------------------------
// Extract a clip from start_time to end_time
// Times are strings: "HH:MM:SS" or seconds as string.
// Returns output path on success, null on failure.
// ---------------------------------------------------------------------------

function extract_clip(
    string $inputPath,
    string $outputDir,
    string $startTime,
    string $endTime,
    string $label = ''
): ?string {
    if (!is_file($inputPath)) {
        return null;
    }

    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    $suffix   = $label ? '_' . preg_replace('/[^a-z0-9]/i', '_', $label) : '';
    $outFile  = 'clip_' . time() . $suffix . '.mp4';
    $outPath  = rtrim($outputDir, '/') . '/' . $outFile;

    $cmd = sprintf(
        '%s -y -ss %s -to %s -i %s -c:v libx264 -c:a aac -movflags +faststart %s 2>&1',
        ffmpeg_bin(),
        escapeshellarg($startTime),
        escapeshellarg($endTime),
        escapeshellarg($inputPath),
        escapeshellarg($outPath)
    );

    exec($cmd, $output, $returnCode);

    if ($returnCode !== 0) {
        error_log('[PTMD VideoProcessor] clip extraction failed: ' . implode("\n", $output));
        return null;
    }

    return $outPath;
}

// ---------------------------------------------------------------------------
// Composite an overlay PNG onto a video
//
// $position: top-left | top-right | bottom-left | bottom-right | center | full
// $opacity:  0.0–1.0
// $scalePercent: overlay width as % of video width (1–100)
// ---------------------------------------------------------------------------

function apply_overlay_to_video(
    string $inputVideoPath,
    string $overlayImagePath,
    string $outputPath,
    string $position    = 'bottom-right',
    float  $opacity     = 1.0,
    int    $scalePercent = 30
): array {
    if (!is_file($inputVideoPath)) {
        return ['ok' => false, 'error' => 'Input video not found: ' . $inputVideoPath];
    }

    if (!is_file($overlayImagePath)) {
        return ['ok' => false, 'error' => 'Overlay image not found: ' . $overlayImagePath];
    }

    $outDir = dirname($outputPath);
    if (!is_dir($outDir)) {
        mkdir($outDir, 0755, true);
    }

    // Scale the overlay to scalePercent% of video width, maintain aspect ratio
    $scaleFilter = "scale=iw*{$scalePercent}/100:-1";

    // Opacity filter: use colorchannelmixer alpha if < 1.0
    $opacityFilter = '';
    if ($opacity < 1.0) {
        $alpha         = number_format(max(0.0, min(1.0, $opacity)), 2);
        $opacityFilter = ",colorchannelmixer=aa={$alpha}";
    }

    // Position expression
    $posMap = [
        'top-left'     => '10:10',
        'top-right'    => 'W-w-10:10',
        'bottom-left'  => '10:H-h-10',
        'bottom-right' => 'W-w-10:H-h-10',
        'center'       => '(W-w)/2:(H-h)/2',
        'full'         => '0:0',
    ];

    $overlayExpr = $posMap[$position] ?? $posMap['bottom-right'];

    // For 'full', scale overlay to match video exactly
    if ($position === 'full') {
        $scaleFilter = 'scale=iw:ih';
    }

    $filterComplex = sprintf(
        '[1:v]%s%s[ovr];[0:v][ovr]overlay=%s',
        $scaleFilter,
        $opacityFilter,
        $overlayExpr
    );

    $cmd = sprintf(
        '%s -y -i %s -i %s -filter_complex %s -c:v libx264 -crf 20 -preset fast -c:a copy -movflags +faststart %s 2>&1',
        ffmpeg_bin(),
        escapeshellarg($inputVideoPath),
        escapeshellarg($overlayImagePath),
        escapeshellarg($filterComplex),
        escapeshellarg($outputPath)
    );

    exec($cmd, $rawOutput, $returnCode);

    if ($returnCode !== 0) {
        return [
            'ok'      => false,
            'error'   => 'FFmpeg failed (exit ' . $returnCode . ')',
            'command' => $cmd,
            'output'  => implode("\n", $rawOutput),
        ];
    }

    return [
        'ok'      => true,
        'output'  => $outputPath,
        'command' => $cmd,
    ];
}

// ---------------------------------------------------------------------------
// Process a single overlay_batch_items row
// Updates DB status during processing.
// ---------------------------------------------------------------------------

function process_batch_item(PDO $pdo, array $item, array $job): void
{
    $uploadBase = rtrim($GLOBALS['config']['upload_dir'], '/');

    // Mark as processing
    $pdo->prepare(
        'UPDATE overlay_batch_items SET status = "processing", updated_at = NOW() WHERE id = :id'
    )->execute(['id' => $item['id']]);

    $inputAbs   = $uploadBase . '/' . ltrim($item['source_path'], '/');
    $overlayAbs = $_SERVER['DOCUMENT_ROOT'] . $job['overlay_path'];   // absolute on disk

    // Build output filename
    $outSubdir  = 'clips/processed';
    $outDir     = $uploadBase . '/' . $outSubdir;
    $outFile    = 'ptmd_overlay_' . $item['id'] . '_' . time() . '.mp4';
    $outAbs     = $outDir . '/' . $outFile;
    $outRel     = $outSubdir . '/' . $outFile;

    $result = apply_overlay_to_video(
        $inputAbs,
        $overlayAbs,
        $outAbs,
        $job['position'],
        (float) $job['opacity'],
        (int) $job['scale']
    );

    if ($result['ok']) {
        $pdo->prepare(
            'UPDATE overlay_batch_items
             SET status = "done", output_path = :out, ffmpeg_command = :cmd, updated_at = NOW()
             WHERE id = :id'
        )->execute([
            'out' => $outRel,
            'cmd' => $result['command'],
            'id'  => $item['id'],
        ]);
    } else {
        $pdo->prepare(
            'UPDATE overlay_batch_items
             SET status = "failed", error_message = :err, ffmpeg_command = :cmd, updated_at = NOW()
             WHERE id = :id'
        )->execute([
            'err' => $result['error'] . "\n" . ($result['output'] ?? ''),
            'cmd' => $result['command'] ?? '',
            'id'  => $item['id'],
        ]);
    }

    // Update job counters
    $pdo->prepare(
        'UPDATE overlay_batch_jobs
         SET done_items = (SELECT COUNT(*) FROM overlay_batch_items WHERE batch_job_id = :jid AND status = "done"),
             updated_at = NOW()
         WHERE id = :jid'
    )->execute(['jid' => $job['id']]);
}

// ---------------------------------------------------------------------------
// Apply time-windowed overlay triggers to a video
//
// $triggers: array of episode_overlay_triggers rows (timestamp_in, timestamp_out,
//            overlay_path, position, opacity, scale, animation_style).
// Returns the same shape as apply_overlay_to_video().
// ---------------------------------------------------------------------------

function apply_timed_overlays(
    string $inputVideoPath,
    array  $triggers,
    string $outputPath
): array {
    if (!is_file($inputVideoPath)) {
        return ['ok' => false, 'error' => 'Input video not found: ' . $inputVideoPath];
    }

    if (empty($triggers)) {
        return ['ok' => false, 'error' => 'No triggers provided'];
    }

    $outDir = dirname($outputPath);
    if (!is_dir($outDir)) {
        mkdir($outDir, 0755, true);
    }

    // Position expressions (same as apply_overlay_to_video)
    $posMap = [
        'top-left'     => '10:10',
        'top-right'    => 'W-w-10:10',
        'bottom-left'  => '10:H-h-10',
        'bottom-right' => 'W-w-10:H-h-10',
        'center'       => '(W-w)/2:(H-h)/2',
        'full'         => '0:0',
    ];

    // Build filter_complex with one input per trigger overlay
    // Input 0 = main video, inputs 1..N = overlay images
    $inputArgs   = '';
    $filterParts = [];
    $prev        = '[0:v]';

    foreach ($triggers as $idx => $trigger) {
        $overlayAbsPath = $_SERVER['DOCUMENT_ROOT'] . $trigger['overlay_path'];
        if (!is_file($overlayAbsPath)) {
            continue;
        }

        $inputArgs .= ' -i ' . escapeshellarg($overlayAbsPath);

        $scalePct    = max(5, min(100, (int) $trigger['scale']));
        $scaleFilter = "scale=iw*{$scalePct}/100:-1";

        $opacity = (float) $trigger['opacity'];
        $alphaFilter = '';
        if ($opacity < 1.0) {
            $alpha       = number_format(max(0.0, min(1.0, $opacity)), 2);
            $alphaFilter = ",colorchannelmixer=aa={$alpha}";
        }

        $position   = $posMap[$trigger['position']] ?? $posMap['bottom-right'];
        $tsIn       = number_format((float) $trigger['timestamp_in'],  3, '.', '');
        $tsOut      = number_format((float) $trigger['timestamp_out'], 3, '.', '');

        // Animation modifier for enable expression
        $anim = $trigger['animation_style'] ?? 'none';
        $enableExpr = "between(t,{$tsIn},{$tsOut})";

        $inLabel  = (string) ($idx + 1);
        $ovLabel  = "ovr{$inLabel}";
        $outLabel = "vout{$inLabel}";

        // Scale + alpha on overlay input, then composite with time enable
        $filterParts[] = "[{$inLabel}:v]{$scaleFilter}{$alphaFilter}[{$ovLabel}]";
        $filterParts[] = "{$prev}[{$ovLabel}]overlay={$position}:enable='{$enableExpr}'[{$outLabel}]";
        $prev = "[{$outLabel}]";
    }

    if (empty($filterParts)) {
        return ['ok' => false, 'error' => 'No valid overlay image files found for triggers'];
    }

    $filterComplex = implode(';', $filterParts);

    $cmd = sprintf(
        '%s -y -i %s%s -filter_complex %s -map %s -c:v libx264 -crf 20 -preset fast -c:a copy -movflags +faststart %s 2>&1',
        ffmpeg_bin(),
        escapeshellarg($inputVideoPath),
        $inputArgs,
        escapeshellarg($filterComplex),
        escapeshellarg($prev),
        escapeshellarg($outputPath)
    );

    exec($cmd, $rawOutput, $returnCode);

    if ($returnCode !== 0) {
        return [
            'ok'      => false,
            'error'   => 'FFmpeg failed (exit ' . $returnCode . ')',
            'command' => $cmd,
            'output'  => implode("\n", $rawOutput),
        ];
    }

    return [
        'ok'      => true,
        'output'  => $outputPath,
        'command' => $cmd,
    ];
}

// ---------------------------------------------------------------------------
// Concatenate intro + main video + outro using the concat demuxer
//
// $introPaths / $outroPath can be absolute filesystem paths or null to skip.
// Returns same shape as apply_overlay_to_video().
// ---------------------------------------------------------------------------

function concat_intro_outro(
    string  $mainVideoPath,
    ?string $introPath,
    ?string $outroPath,
    string  $outputPath
): array {
    if (!is_file($mainVideoPath)) {
        return ['ok' => false, 'error' => 'Main video not found: ' . $mainVideoPath];
    }

    $outDir = dirname($outputPath);
    if (!is_dir($outDir)) {
        mkdir($outDir, 0755, true);
    }

    // Build segments list
    $segments = [];
    if ($introPath && is_file($introPath)) {
        $segments[] = $introPath;
    }
    $segments[] = $mainVideoPath;
    if ($outroPath && is_file($outroPath)) {
        $segments[] = $outroPath;
    }

    if (count($segments) === 1) {
        // Only main video, no-op: just copy
        $cmd = sprintf(
            '%s -y -i %s -c copy %s 2>&1',
            ffmpeg_bin(),
            escapeshellarg($mainVideoPath),
            escapeshellarg($outputPath)
        );
        exec($cmd, $rawOut, $rc);
        return $rc === 0
            ? ['ok' => true, 'output' => $outputPath, 'command' => $cmd]
            : ['ok' => false, 'error' => 'FFmpeg copy failed', 'command' => $cmd, 'output' => implode("\n", $rawOut)];
    }

    // Build concat filter: re-encode to ensure matching streams
    $inputArgs   = '';
    $filterParts = [];
    foreach ($segments as $i => $seg) {
        $inputArgs .= ' -i ' . escapeshellarg($seg);
        $filterParts[] = "[{$i}:v][{$i}:a]";
    }
    $n = count($segments);
    $filterComplex = implode('', $filterParts) . "concat=n={$n}:v=1:a=1[vout][aout]";

    $cmd = sprintf(
        '%s -y%s -filter_complex %s -map [vout] -map [aout] -c:v libx264 -crf 20 -preset fast -c:a aac -movflags +faststart %s 2>&1',
        ffmpeg_bin(),
        $inputArgs,
        escapeshellarg($filterComplex),
        escapeshellarg($outputPath)
    );

    exec($cmd, $rawOutput, $returnCode);

    if ($returnCode !== 0) {
        return [
            'ok'      => false,
            'error'   => 'FFmpeg concat failed (exit ' . $returnCode . ')',
            'command' => $cmd,
            'output'  => implode("\n", $rawOutput),
        ];
    }

    return [
        'ok'      => true,
        'output'  => $outputPath,
        'command' => $cmd,
    ];
}

// ---------------------------------------------------------------------------
// Render a video with a full export profile (resize, bitrate, optional intro/outro,
// optional watermark, optional timeline triggers).
//
// $episode:  row from episodes table.
// $profile:  row from export_profiles table.
// $triggers: rows from episode_overlay_triggers (may be empty).
// Returns ['ok' => bool, 'output_path' => string, 'command' => string, 'error' => string]
// ---------------------------------------------------------------------------

function render_with_profile(
    string $inputVideoAbsPath,
    array  $episode,
    array  $profile,
    array  $triggers,
    string $outputPath
): array {
    if (!is_file($inputVideoAbsPath)) {
        return ['ok' => false, 'error' => 'Input video not found: ' . $inputVideoAbsPath];
    }

    $outDir = dirname($outputPath);
    if (!is_dir($outDir)) {
        mkdir($outDir, 0755, true);
    }

    $docRoot  = $_SERVER['DOCUMENT_ROOT'];
    $uploadBase = rtrim($GLOBALS['config']['upload_dir'], '/');

    // ── Step 1: Concat intro/outro (produces tmp file if needed) ─────────────
    $workingPath = $inputVideoAbsPath;
    $tmpPaths    = [];

    if ($profile['use_intro'] || $profile['use_outro']) {
        $introAbsPath = null;
        $outroAbsPath = null;

        if ($profile['use_intro']) {
            $introRel = !empty($episode['intro_asset_path'])
                ? $episode['intro_asset_path']
                : site_setting('intro_asset_path', '');
            if ($introRel !== '') {
                $introAbsPath = $docRoot . $introRel;
            }
        }

        if ($profile['use_outro']) {
            $outroRel = !empty($episode['outro_asset_path'])
                ? $episode['outro_asset_path']
                : site_setting('outro_asset_path', site_setting('outro_asset_path', ''));
            if ($outroRel !== '') {
                $outroAbsPath = $docRoot . $outroRel;
            }
        }

        if ($introAbsPath || $outroAbsPath) {
            $tmpConcat = $uploadBase . '/exports/tmp_concat_' . uniqid('', true) . '.mp4';
            $r = concat_intro_outro($workingPath, $introAbsPath, $outroAbsPath, $tmpConcat);
            if (!$r['ok']) {
                return $r;
            }
            $tmpPaths[]  = $tmpConcat;
            $workingPath = $tmpConcat;
        }
    }

    // ── Step 2: Timeline overlay triggers ────────────────────────────────────
    if ($profile['use_triggers'] && !empty($triggers)) {
        $tmpTrig = $uploadBase . '/exports/tmp_trig_' . uniqid('', true) . '.mp4';
        $r = apply_timed_overlays($workingPath, $triggers, $tmpTrig);
        if (!$r['ok']) {
            foreach ($tmpPaths as $t) { @unlink($t); }
            return $r;
        }
        $tmpPaths[]  = $tmpTrig;
        $workingPath = $tmpTrig;
    }

    // ── Step 3: Watermark (static, full-clip) ─────────────────────────────────
    if ($profile['use_watermark']) {
        $wmRel = site_setting('watermark_asset_path', '');
        $wmAbs = $wmRel !== '' ? $docRoot . $wmRel : null;
        if ($wmAbs && is_file($wmAbs)) {
            $tmpWm = $uploadBase . '/exports/tmp_wm_' . uniqid('', true) . '.mp4';
            $r = apply_overlay_to_video($workingPath, $wmAbs, $tmpWm, 'bottom-right', 0.9, 15);
            if ($r['ok']) {
                $tmpPaths[]  = $tmpWm;
                $workingPath = $tmpWm;
            }
            // Non-fatal: continue even if watermark fails
        }
    }

    // ── Step 4: Encode to target profile (resize, bitrate) ───────────────────
    $w   = (int) $profile['width'];
    $h   = (int) $profile['height'];
    $fps = (int) $profile['fps'];
    $vbr = $profile['video_bitrate'];
    $abr = $profile['audio_bitrate'];

    $extraFlags = trim((string) ($profile['extra_ffmpeg_flags'] ?? ''));

    $cmd = sprintf(
        '%s -y -i %s -vf "scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2" -r %d -b:v %s -c:v libx264 -crf 20 -preset fast -b:a %s -c:a aac %s -movflags +faststart %s 2>&1',
        ffmpeg_bin(),
        escapeshellarg($workingPath),
        $w, $h, $w, $h,
        $fps,
        escapeshellarg($vbr),
        escapeshellarg($abr),
        $extraFlags,
        escapeshellarg($outputPath)
    );

    exec($cmd, $rawOutput, $returnCode);

    // Clean up temps
    foreach ($tmpPaths as $t) {
        @unlink($t);
    }

    if ($returnCode !== 0) {
        return [
            'ok'      => false,
            'error'   => 'FFmpeg encode failed (exit ' . $returnCode . ')',
            'command' => $cmd,
            'output'  => implode("\n", $rawOutput),
        ];
    }

    return [
        'ok'          => true,
        'output_path' => $outputPath,
        'command'     => $cmd,
    ];
}

// ---------------------------------------------------------------------------
// Execute a single export_jobs row end-to-end
// Updates DB status throughout; returns ['ok', 'output_path', 'error']
// ---------------------------------------------------------------------------

function run_export_job(PDO $pdo, int $jobId, array $episode, array $profile): array
{
    $uploadBase = rtrim($GLOBALS['config']['upload_dir'], '/');
    $exportsDir = $uploadBase . '/exports';
    if (!is_dir($exportsDir)) {
        mkdir($exportsDir, 0755, true);
    }

    // Mark processing
    $pdo->prepare(
        'UPDATE export_jobs SET status = "processing", started_at = NOW(), updated_at = NOW() WHERE id = :id'
    )->execute(['id' => $jobId]);

    // Load triggers for this episode
    $triggers = [];
    if ($profile['use_triggers']) {
        $tStmt = $pdo->prepare(
            'SELECT * FROM episode_overlay_triggers WHERE episode_id = :eid ORDER BY sort_order, id'
        );
        $tStmt->execute(['eid' => $episode['id']]);
        $triggers = $tStmt->fetchAll();
    }

    $inputAbsPath = $uploadBase . '/' . ltrim((string) $episode['video_file_path'], '/');
    $outFile      = 'export_' . $jobId . '_ep' . $episode['id'] . '_' . time() . '.mp4';
    $outAbs       = $exportsDir . '/' . $outFile;
    $outRel       = 'exports/' . $outFile;

    $result = render_with_profile($inputAbsPath, $episode, $profile, $triggers, $outAbs);

    if ($result['ok']) {
        $pdo->prepare(
            'UPDATE export_jobs
             SET status = "completed", output_path = :out, ffmpeg_command = :cmd,
                 completed_at = NOW(), updated_at = NOW()
             WHERE id = :id'
        )->execute(['out' => $outRel, 'cmd' => $result['command'], 'id' => $jobId]);
    } else {
        $pdo->prepare(
            'UPDATE export_jobs
             SET status = "failed", error_message = :err, ffmpeg_command = :cmd,
                 completed_at = NOW(), updated_at = NOW()
             WHERE id = :id'
        )->execute([
            'err' => $result['error'] . "\n" . ($result['output'] ?? ''),
            'cmd' => $result['command'] ?? '',
            'id'  => $jobId,
        ]);
    }

    return array_merge($result, ['output_path' => $outRel]);
}


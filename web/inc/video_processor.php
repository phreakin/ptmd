<?php
/**
 * PTMD — Video Processor Helpers (inc/video_processor.php)
 *
 * Wraps FFmpeg/FFprobe calls for:
 *  - Probing video duration/metadata
 *  - Extracting a clip (start → end time)
 *  - Compositing an overlay PNG onto a video (single or multi-layer)
 *  - Burning captions into a video (drawtext filter)
 *  - Batch overlay processing via the DB queue (legacy overlay_batch_*)
 *  - Edit-job pipeline: process_edit_job_output(), run_edit_job_worker()
 *
 * All commands are logged to overlay_batch_items/edit_job_outputs.ffmpeg_command
 * for debugging.
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
// Allowed path-prefix list used by new pipeline functions
// ---------------------------------------------------------------------------

/** @internal */
function _vp_allowed_prefixes(): array
{
    return ['/uploads/', '/assets/brand/'];
}

/**
 * Verify that $path starts with one of the allowed web-root prefixes.
 * Prevents path traversal in shell commands.
 */
function vp_is_safe_path(string $path): bool
{
    // Reject any path that contains directory traversal sequences
    if (str_contains($path, '..')) {
        return false;
    }
    foreach (_vp_allowed_prefixes() as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------------------
// Build FFmpeg filter_complex for one image layer
//
// $inputIndex: FFmpeg input index for this overlay image (e.g. 1, 2, …)
// $baseStream:  the named stream coming in  (e.g. '[0:v]' or '[prev]')
// $outputLabel: the named stream going out  (e.g. '[out0]')
// $layer: array with keys:
//   position     string  top-left|top-right|bottom-left|bottom-right|center|full
//   scale        int     overlay width as % of video width (1–100)
//   opacity      float   0.0–1.0
//   start_sec    float|null  show overlay only after this many seconds
//   end_sec      float|null  hide overlay after this many seconds
// ---------------------------------------------------------------------------

function build_image_layer_filter(
    int    $inputIndex,
    string $baseStream,
    string $outputLabel,
    array  $layer
): string {
    $position = $layer['position'] ?? 'bottom-right';
    $scale    = max(1, min(100, (int) ($layer['scale'] ?? 30)));
    $opacity  = max(0.0, min(1.0, (float) ($layer['opacity'] ?? 1.0)));

    $posMap = [
        'top-left'     => '10:10',
        'top-right'    => 'W-w-10:10',
        'bottom-left'  => '10:H-h-10',
        'bottom-right' => 'W-w-10:H-h-10',
        'center'       => '(W-w)/2:(H-h)/2',
        'full'         => '0:0',
    ];

    $scaleFilter = $position === 'full'
        ? 'scale=iw:ih'
        : "scale=iw*{$scale}/100:-1";

    $opacityPart = '';
    if ($opacity < 1.0) {
        $alpha = number_format($opacity, 2, '.', '');
        $opacityPart = ",colorchannelmixer=aa={$alpha}";
    }

    $overlayExpr = $posMap[$position] ?? $posMap['bottom-right'];

    // Optional time window
    $enablePart = '';
    $startSec = isset($layer['start_sec']) ? (float) $layer['start_sec'] : null;
    $endSec   = isset($layer['end_sec'])   ? (float) $layer['end_sec']   : null;
    if ($startSec !== null && $endSec !== null) {
        $enablePart = ":enable='between(t," . number_format($startSec, 3, '.', '') . ',' . number_format($endSec, 3, '.', '') . ")'";
    } elseif ($startSec !== null) {
        $enablePart = ":enable='gte(t," . number_format($startSec, 3, '.', '') . ")'";
    } elseif ($endSec !== null) {
        $enablePart = ":enable='lte(t," . number_format($endSec, 3, '.', '') . ")'";
    }

    $ovr = '[ovr' . $inputIndex . ']';

    return sprintf(
        '[%d:v]%s%s%s;%s[%d:v]overlay=%s%s%s',
        $inputIndex, $scaleFilter, $opacityPart, $ovr,
        $baseStream, $inputIndex, $overlayExpr, $enablePart, $outputLabel
    );
}

// ---------------------------------------------------------------------------
// Composite multiple image layers onto a video
//
// $inputVideoPath:  absolute path to source video
// $layers:          array of layer definitions; each element:
//   path      string  web-root-relative path (e.g. /assets/brand/…)
//   position  string
//   scale     int
//   opacity   float
//   start_sec float|null
//   end_sec   float|null
// $outputPath:      absolute path for the output file
//
// Returns ['ok'=>bool, 'output'=>string, 'command'=>string, 'error'=>string]
// ---------------------------------------------------------------------------

function apply_multi_layer_composition(
    string $inputVideoPath,
    array  $layers,
    string $outputPath
): array {
    if (!is_file($inputVideoPath)) {
        return ['ok' => false, 'error' => 'Input video not found: ' . $inputVideoPath];
    }

    if (empty($layers)) {
        return ['ok' => false, 'error' => 'No image layers provided'];
    }

    $docRoot  = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    $outDir   = dirname($outputPath);
    if (!is_dir($outDir)) {
        mkdir($outDir, 0755, true);
    }

    // Validate and resolve each layer path
    $resolvedLayers = [];
    foreach ($layers as $layer) {
        $webPath = trim((string) ($layer['path'] ?? ''));
        if (!vp_is_safe_path($webPath)) {
            return ['ok' => false, 'error' => 'Unsafe layer path: ' . $webPath];
        }
        $absPath = $docRoot . $webPath;
        if (!is_file($absPath)) {
            return ['ok' => false, 'error' => 'Layer image not found: ' . $webPath];
        }
        $resolvedLayers[] = array_merge($layer, ['_abs' => $absPath]);
    }

    // Build -i arguments and filter_complex
    $inputs       = escapeshellarg($inputVideoPath);
    $filterParts  = [];
    $baseStream   = '[0:v]';
    $inputIdx     = 1;

    foreach ($resolvedLayers as $i => $layer) {
        $inputs .= ' -i ' . escapeshellarg($layer['_abs']);
        $isLast      = ($i === count($resolvedLayers) - 1);
        $outputLabel = $isLast ? '[vout]' : ('[v' . $i . ']');
        $filterParts[] = build_image_layer_filter($inputIdx, $baseStream, $outputLabel, $layer);
        $baseStream  = $outputLabel;
        $inputIdx++;
    }

    $filterComplex = implode(';', $filterParts);

    $cmd = sprintf(
        '%s -y -i %s -filter_complex %s -map "[vout]" -map 0:a? -c:v libx264 -crf 20 -preset fast -c:a copy -movflags +faststart %s 2>&1',
        ffmpeg_bin(),
        $inputs,
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
// Burn a caption string into a video using FFmpeg drawtext filter.
//
// $captionText:   plain text to burn in (will be sanitized for ffmpeg filter)
// $outputPath:    absolute path for the output file
// Returns ['ok'=>bool, 'output'=>string, 'command'=>string, 'error'=>string]
// ---------------------------------------------------------------------------

function burn_caption_to_video(
    string $inputVideoPath,
    string $captionText,
    string $outputPath,
    string $position    = 'bottom',
    int    $fontSize    = 28,
    string $fontColor   = 'white',
    string $boxColor    = 'black@0.6'
): array {
    if (!is_file($inputVideoPath)) {
        return ['ok' => false, 'error' => 'Input video not found: ' . $inputVideoPath];
    }

    $outDir = dirname($outputPath);
    if (!is_dir($outDir)) {
        mkdir($outDir, 0755, true);
    }

    // Sanitize caption text: escape single-quotes and backslashes for the drawtext filter.
    // Drawtext uses ':' and "'" as delimiters — these must be escaped.
    $safeText = str_replace(
        ['\\', "'", ':', '[', ']'],
        ['\\\\', "\\'", '\\:', '\\[', '\\]'],
        $captionText
    );

    // Truncate overly long captions to avoid filter overflows (max 500 chars)
    if (mb_strlen($safeText) > 500) {
        $safeText = mb_substr($safeText, 0, 497) . '...';
    }

    $yExpr = $position === 'top' ? '20' : 'h-th-20';

    $fontSize  = max(10, min(80, $fontSize));
    $safeColor = preg_replace('/[^a-zA-Z0-9#@.]/', '', $fontColor) ?: 'white';
    $safeBox   = preg_replace('/[^a-zA-Z0-9#@.]/', '', $boxColor)  ?: 'black@0.6';

    $drawFilter = sprintf(
        "drawtext=text='%s':fontsize=%d:fontcolor=%s:box=1:boxcolor=%s:boxborderw=6:x=(w-text_w)/2:y=%s:line_spacing=5",
        $safeText,
        $fontSize,
        $safeColor,
        $safeBox,
        $yExpr
    );

    $cmd = sprintf(
        '%s -y -i %s -vf %s -c:v libx264 -crf 20 -preset fast -c:a copy -movflags +faststart %s 2>&1',
        ffmpeg_bin(),
        escapeshellarg($inputVideoPath),
        escapeshellarg($drawFilter),
        escapeshellarg($outputPath)
    );

    exec($cmd, $rawOutput, $returnCode);

    if ($returnCode !== 0) {
        return [
            'ok'      => false,
            'error'   => 'FFmpeg caption burn failed (exit ' . $returnCode . ')',
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
// Process a single edit_job_outputs row through the composition pipeline.
//
// Pipeline:
//   1. Start with source_path from the parent edit_job
//   2. Apply image layers (if any) via apply_multi_layer_composition()
//   3. Burn caption (if mode=embedded) via burn_caption_to_video()
//   4. Update edit_job_outputs status in DB
//   5. Optionally create social_post_queue entry
// ---------------------------------------------------------------------------

function process_edit_job_output(PDO $pdo, array $output, array $job): void
{
    $uploadBase = rtrim($GLOBALS['config']['upload_dir'] ?? '', '/');

    $pdo->prepare(
        'UPDATE edit_job_outputs SET status = "processing", updated_at = NOW() WHERE id = :id'
    )->execute(['id' => $output['id']]);

    $sourceAbs = $uploadBase . '/' . ltrim((string) $job['source_path'], '/');

    if (!is_file($sourceAbs)) {
        $pdo->prepare(
            'UPDATE edit_job_outputs
             SET status = "failed", error_message = :err, updated_at = NOW()
             WHERE id = :id'
        )->execute(['err' => 'Source file not found: ' . $job['source_path'], 'id' => $output['id']]);
        return;
    }

    // Build output path
    $outSubdir = 'clips/processed';
    $outDir    = $uploadBase . '/' . $outSubdir;
    $platform  = preg_replace('/[^a-z0-9_]/i', '_', (string) $output['platform']);
    $outFile   = 'edit_job_' . $output['id'] . '_' . $platform . '_' . time() . '.mp4';
    $outAbs    = $outDir . '/' . $outFile;
    $outRel    = $outSubdir . '/' . $outFile;

    $currentInput = $sourceAbs;
    $lastCommand  = '';

    // Step 1: Image layers
    $imageLayers = [];
    if (!empty($output['image_layers_json'])) {
        $decoded = json_decode((string) $output['image_layers_json'], true);
        if (is_array($decoded)) {
            $imageLayers = $decoded;
        }
    }

    // Also fold the single overlay_path (legacy/simple) into layers if set
    if (!empty($output['overlay_path'])) {
        array_unshift($imageLayers, [
            'path'     => (string) $output['overlay_path'],
            'position' => 'bottom-right',
            'scale'    => 30,
            'opacity'  => 1.0,
        ]);
    }

    if (!empty($imageLayers)) {
        $layerOutAbs = $outDir . '/tmp_layers_' . $output['id'] . '_' . time() . '.mp4';
        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }
        $result = apply_multi_layer_composition($currentInput, $imageLayers, $layerOutAbs);
        if (!$result['ok']) {
            $pdo->prepare(
                'UPDATE edit_job_outputs
                 SET status = "failed", error_message = :err, ffmpeg_command = :cmd, updated_at = NOW()
                 WHERE id = :id'
            )->execute([
                'err' => $result['error'] . "\n" . ($result['output'] ?? ''),
                'cmd' => $result['command'] ?? '',
                'id'  => $output['id'],
            ]);
            return;
        }
        $lastCommand  = $result['command'];
        $currentInput = $layerOutAbs;
    }

    // Step 2: Caption burn-in
    $captionMode = (string) $output['caption_mode'];
    if ($captionMode === 'embedded') {
        $captionText = '';
        // Try to look up a caption from clip_captions linked to this job
        $capRow = $pdo->prepare(
            'SELECT caption_text FROM clip_captions WHERE job_id = :jid ORDER BY id LIMIT 1'
        );
        $capRow->execute(['jid' => $job['id']]);
        $capData = $capRow->fetch();
        if ($capData && !empty($capData['caption_text'])) {
            $captionText = (string) $capData['caption_text'];
        }

        if ($captionText !== '') {
            $captionOutAbs = $outDir . '/tmp_caption_' . $output['id'] . '_' . time() . '.mp4';
            $result = burn_caption_to_video($currentInput, $captionText, $captionOutAbs);
            if (!$result['ok']) {
                // Clean up temp files before failure
                if ($currentInput !== $sourceAbs && is_file($currentInput)) {
                    @unlink($currentInput);
                }
                $pdo->prepare(
                    'UPDATE edit_job_outputs
                     SET status = "failed", error_message = :err, ffmpeg_command = :cmd, updated_at = NOW()
                     WHERE id = :id'
                )->execute([
                    'err' => $result['error'] . "\n" . ($result['output'] ?? ''),
                    'cmd' => $result['command'] ?? '',
                    'id'  => $output['id'],
                ]);
                return;
            }
            // Clean up intermediate layer file
            if ($currentInput !== $sourceAbs && is_file($currentInput)) {
                @unlink($currentInput);
            }
            $lastCommand  = $result['command'];
            $currentInput = $captionOutAbs;
        }
    }

    // Step 3: Move/rename to final output path
    if (!is_dir($outDir)) {
        mkdir($outDir, 0755, true);
    }

    if ($currentInput === $sourceAbs) {
        // No processing was applied — copy source to output
        if (!copy($sourceAbs, $outAbs)) {
            $pdo->prepare(
                'UPDATE edit_job_outputs
                 SET status = "failed", error_message = :err, updated_at = NOW()
                 WHERE id = :id'
            )->execute(['err' => 'Failed to copy source to output.', 'id' => $output['id']]);
            return;
        }
    } else {
        // Rename final temp file to canonical output path
        if (!rename($currentInput, $outAbs)) {
            @unlink($currentInput);
            $pdo->prepare(
                'UPDATE edit_job_outputs
                 SET status = "failed", error_message = :err, updated_at = NOW()
                 WHERE id = :id'
            )->execute(['err' => 'Failed to finalize output file.', 'id' => $output['id']]);
            return;
        }
    }

    // Step 4: Update output row to done
    $pdo->prepare(
        'UPDATE edit_job_outputs
         SET status = "done", output_path = :out, ffmpeg_command = :cmd, updated_at = NOW()
         WHERE id = :id'
    )->execute([
        'out' => $outRel,
        'cmd' => $lastCommand,
        'id'  => $output['id'],
    ]);

    // Step 5: Optionally create a social_post_queue entry
    // Reads platform preferences if available; skips gracefully if table is absent.
    try {
        $platform = (string) $output['platform'];
        if ($platform !== 'generic' && $platform !== '') {
            $pref = $pdo->prepare(
                'SELECT * FROM social_platform_preferences WHERE platform = :p AND is_enabled = 1 LIMIT 1'
            );
            $pref->execute(['p' => $platform]);
            $prefRow = $pref->fetch();

            if ($prefRow) {
                $caption = trim(
                    ((string) ($prefRow['default_caption_prefix'] ?? '')) . ' ' .
                    ((string) ($prefRow['default_hashtags']       ?? ''))
                );
                $defaultStatus = $prefRow['default_status'] ?? 'queued';
                $queueStmt = $pdo->prepare(
                    'INSERT INTO social_post_queue
                     (clip_id, platform, content_type, caption, asset_path, scheduled_for, status, created_at, updated_at)
                     VALUES (:clip, :platform, :ct, :caption, :asset, NOW() + INTERVAL 1 DAY, :status, NOW(), NOW())'
                );
                $queueStmt->execute([
                    'clip'     => $job['source_clip_id'] ?: null,
                    'platform' => $platform,
                    'ct'       => (string) ($prefRow['default_content_type'] ?? 'clip'),
                    'caption'  => $caption,
                    'asset'    => $outRel,
                    'status'   => $defaultStatus,
                ]);
                $queueItemId = (int) $pdo->lastInsertId();
                $pdo->prepare(
                    'UPDATE edit_job_outputs SET queue_item_id = :qid, updated_at = NOW() WHERE id = :id'
                )->execute(['qid' => $queueItemId, 'id' => $output['id']]);
            }
        }
    } catch (\Throwable $e) {
        // Queue creation failure is non-fatal; log and continue
        error_log('[PTMD EditJob] Social queue creation failed for output #' . $output['id'] . ': ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Run the edit-job worker: process all pending outputs for pending/processing jobs.
//
// $maxJobs:    maximum number of jobs to process in this invocation (0 = unlimited)
// $maxOutputs: maximum number of outputs to process in total
//
// Returns a summary array: ['processed'=>N, 'failed'=>N, 'errors'=>[…]]
// ---------------------------------------------------------------------------

function run_edit_job_worker(PDO $pdo, int $maxJobs = 0, int $maxOutputs = 50): array
{
    $processedOutputs = 0;
    $failedOutputs    = 0;
    $errors           = [];
    $jobsProcessed    = 0;

    // Fetch jobs with pending outputs; lock by setting to 'processing'
    $jobsStmt = $pdo->query(
        'SELECT DISTINCT ej.*
         FROM edit_jobs ej
         JOIN edit_job_outputs ejo ON ejo.job_id = ej.id AND ejo.status = "pending"
         WHERE ej.status IN ("pending","processing")
         ORDER BY ej.created_at ASC'
        . ($maxJobs > 0 ? ' LIMIT ' . $maxJobs : '')
    );

    foreach ($jobsStmt->fetchAll() as $job) {
        // Mark job as processing
        $pdo->prepare(
            'UPDATE edit_jobs SET status = "processing", updated_at = NOW() WHERE id = :id'
        )->execute(['id' => $job['id']]);

        // Fetch this job's pending outputs
        $outputsStmt = $pdo->prepare(
            'SELECT * FROM edit_job_outputs WHERE job_id = :jid AND status = "pending" ORDER BY id'
        );
        $outputsStmt->execute(['jid' => $job['id']]);

        foreach ($outputsStmt->fetchAll() as $output) {
            if ($maxOutputs > 0 && $processedOutputs >= $maxOutputs) {
                break 2;
            }

            // Check retry limit
            if ((int) $output['retry_count'] >= (int) $job['max_retries']) {
                $pdo->prepare(
                    'UPDATE edit_job_outputs
                     SET status = "failed", error_message = "Max retries exceeded", updated_at = NOW()
                     WHERE id = :id'
                )->execute(['id' => $output['id']]);
                $failedOutputs++;
                $errors[] = 'Output #' . $output['id'] . ': max retries exceeded';
                continue;
            }

            try {
                process_edit_job_output($pdo, $output, $job);
            } catch (\Throwable $e) {
                $errMsg = 'Exception: ' . $e->getMessage();
                $pdo->prepare(
                    'UPDATE edit_job_outputs
                     SET status = "failed", error_message = :err, updated_at = NOW()
                     WHERE id = :id'
                )->execute(['err' => $errMsg, 'id' => $output['id']]);
                $failedOutputs++;
                $errors[] = 'Output #' . $output['id'] . ': ' . $errMsg;
                continue;
            }

            $processedOutputs++;

            // Re-read status to determine outcome
            $checkStmt = $pdo->prepare('SELECT status FROM edit_job_outputs WHERE id = :id');
            $checkStmt->execute(['id' => $output['id']]);
            $newStatus = (string) $checkStmt->fetchColumn();
            if ($newStatus === 'failed') {
                $failedOutputs++;
                // Increment retry counter
                $pdo->prepare(
                    'UPDATE edit_job_outputs SET retry_count = retry_count + 1, updated_at = NOW() WHERE id = :id'
                )->execute(['id' => $output['id']]);
            }
        }

        // After processing all outputs for this job, determine final job status
        $remainingPending = (int) $pdo->prepare(
            'SELECT COUNT(*) FROM edit_job_outputs WHERE job_id = :jid AND status IN ("pending","processing")'
        )->execute(['jid' => $job['id']]) ?: 0;
        // Re-query properly
        $pendingCheck = $pdo->prepare(
            'SELECT COUNT(*) FROM edit_job_outputs WHERE job_id = :jid AND status IN ("pending","processing")'
        );
        $pendingCheck->execute(['jid' => $job['id']]);
        $remainingPending = (int) $pendingCheck->fetchColumn();

        if ($remainingPending === 0) {
            $failCheck = $pdo->prepare(
                'SELECT COUNT(*) FROM edit_job_outputs WHERE job_id = :jid AND status = "failed"'
            );
            $failCheck->execute(['jid' => $job['id']]);
            $failedCount = (int) $failCheck->fetchColumn();

            $doneCheck = $pdo->prepare(
                'SELECT COUNT(*) FROM edit_job_outputs WHERE job_id = :jid AND status = "done"'
            );
            $doneCheck->execute(['jid' => $job['id']]);
            $doneCount = (int) $doneCheck->fetchColumn();

            if ($doneCount > 0 && $failedCount === 0) {
                $finalStatus = 'completed';
            } elseif ($doneCount > 0) {
                $finalStatus = 'completed'; // partial success — still mark completed
            } else {
                $finalStatus = 'failed';
            }

            $pdo->prepare(
                'UPDATE edit_jobs SET status = :status, updated_at = NOW() WHERE id = :id'
            )->execute(['status' => $finalStatus, 'id' => $job['id']]);
        }

        $jobsProcessed++;
    }

    return [
        'processed' => $processedOutputs,
        'failed'    => $failedOutputs,
        'jobs'      => $jobsProcessed,
        'errors'    => $errors,
    ];
}

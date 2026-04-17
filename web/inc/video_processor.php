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
// Platform profiles — target resolution for each social platform
// ---------------------------------------------------------------------------

/**
 * Return supported platform output profiles.
 * width/height define the target resolution; aspect determines crop strategy.
 */
function get_platform_profiles(): array
{
    return [
        'youtube'         => ['label' => 'YouTube (Full)',    'width' => 1920, 'height' => 1080],
        'youtube_shorts'  => ['label' => 'YouTube Shorts',    'width' => 1080, 'height' => 1920],
        'tiktok'          => ['label' => 'TikTok',            'width' => 1080, 'height' => 1920],
        'instagram_reels' => ['label' => 'Instagram Reels',   'width' => 1080, 'height' => 1920],
        'facebook_reels'  => ['label' => 'Facebook Reels',    'width' => 1080, 'height' => 1920],
        'x'               => ['label' => 'X / Twitter',       'width' => 1280, 'height' =>  720],
    ];
}

// ---------------------------------------------------------------------------
// Generate a platform-optimized clip from a source video.
//
// For vertical platforms (9:16): center-crops a landscape source, then scales.
// For horizontal platforms (16:9): scales + pads with black bars if needed.
// Returns output path on success, null on failure.
// ---------------------------------------------------------------------------

function generate_platform_clip(
    string $inputPath,
    string $outputDir,
    string $platform,
    string $label = ''
): ?string {
    $profiles = get_platform_profiles();

    if (!isset($profiles[$platform])) {
        error_log('[PTMD VideoProcessor] Unknown platform profile: ' . $platform);
        return null;
    }

    if (!is_file($inputPath)) {
        return null;
    }

    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    $w = $profiles[$platform]['width'];
    $h = $profiles[$platform]['height'];

    $suffix  = $label ? '_' . preg_replace('/[^a-z0-9]/i', '_', $label) : '';
    $outFile = 'platform_' . $platform . '_' . time() . $suffix . '.mp4';
    $outPath = rtrim($outputDir, '/') . '/' . $outFile;

    // Build FFmpeg video filter based on target aspect ratio
    if ($h > $w) {
        // Vertical (9:16): center-crop the landscape input to 9:16, then scale
        $vf = "crop=ih*{$w}/{$h}:ih:(iw-ih*{$w}/{$h})/2:0,scale={$w}:{$h}";
    } else {
        // Horizontal (16:9 or 1:1): scale to fit, pad remainder with black
        $vf = "scale={$w}:{$h}:force_original_aspect_ratio=decrease,pad={$w}:{$h}:(ow-iw)/2:(oh-ih)/2:black";
    }

    $cmd = sprintf(
        '%s -y -i %s -vf %s -c:v libx264 -crf 22 -preset fast -c:a aac -movflags +faststart %s 2>&1',
        ffmpeg_bin(),
        escapeshellarg($inputPath),
        escapeshellarg($vf),
        escapeshellarg($outPath)
    );

    exec($cmd, $output, $returnCode);

    if ($returnCode !== 0) {
        error_log('[PTMD VideoProcessor] platform clip failed for ' . $platform . ': ' . implode("\n", $output));
        return null;
    }

    return $outPath;
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

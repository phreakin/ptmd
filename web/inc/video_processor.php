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

    $watermarkPath = resolve_configured_watermark_path();
    if ($watermarkPath && watermark_auto_apply_enabled()) {
        $watermarkedPath = rtrim($outputDir, '/') . '/wm_' . basename($outFile);
        $watermarkResult = apply_watermark_to_video($outPath, $watermarkPath, $watermarkedPath);

        if (!$watermarkResult['ok']) {
            error_log('[PTMD VideoProcessor] auto watermark failed: ' . ($watermarkResult['error'] ?? 'unknown error'));
            @unlink($watermarkedPath);
            @unlink($outPath);
            return null;
        }

        @unlink($outPath);
        rename($watermarkedPath, $outPath);
    }

    return $outPath;
}

function watermark_auto_apply_enabled(): bool
{
    $value = strtolower(trim(site_setting('watermark_auto_apply', '1')));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function resolve_configured_watermark_path(): ?string
{
    $path = trim(site_setting('watermark_asset_path', ''));
    if ($path === '') {
        return null;
    }

    if (!str_starts_with($path, '/uploads/') && !str_starts_with($path, '/assets/')) {
        return null;
    }

    $absolute = $_SERVER['DOCUMENT_ROOT'] . $path;
    return is_file($absolute) ? $absolute : null;
}

function apply_watermark_to_video(
    string $inputVideoPath,
    string $watermarkImagePath,
    string $outputPath,
    float $opacity = 0.85,
    int $scalePercent = 14
): array {
    if (!is_file($inputVideoPath)) {
        return ['ok' => false, 'error' => 'Input video not found: ' . $inputVideoPath];
    }
    if (!is_file($watermarkImagePath)) {
        return ['ok' => false, 'error' => 'Watermark image not found: ' . $watermarkImagePath];
    }

    $outDir = dirname($outputPath);
    if (!is_dir($outDir)) {
        mkdir($outDir, 0755, true);
    }

    $alpha = number_format(max(0.0, min(1.0, $opacity)), 2);
    $wmScale = max(5, min(50, $scalePercent));
    $filter = sprintf(
        '[1:v]scale=iw*%d/100:-1,colorchannelmixer=aa=%s[wm];[0:v][wm]overlay=W-w-16:H-h-16',
        $wmScale,
        $alpha
    );

    $cmd = sprintf(
        '%s -y -i %s -i %s -filter_complex %s -c:v libx264 -crf 20 -preset fast -c:a copy -movflags +faststart %s 2>&1',
        ffmpeg_bin(),
        escapeshellarg($inputVideoPath),
        escapeshellarg($watermarkImagePath),
        escapeshellarg($filter),
        escapeshellarg($outputPath)
    );

    exec($cmd, $rawOutput, $returnCode);
    if ($returnCode !== 0) {
        return [
            'ok' => false,
            'error' => 'FFmpeg watermark pass failed (exit ' . $returnCode . ')',
            'command' => $cmd,
            'output' => implode("\n", $rawOutput),
        ];
    }

    return ['ok' => true, 'output' => $outputPath, 'command' => $cmd];
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
    int    $scalePercent = 30,
    bool   $includeWatermark = true
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

    $filterComplex = sprintf('[1:v]%s%s[ovr];[0:v][ovr]overlay=%s[tmp]', $scaleFilter, $opacityFilter, $overlayExpr);
    $inputArgs = sprintf('-i %s -i %s', escapeshellarg($inputVideoPath), escapeshellarg($overlayImagePath));
    $finalLabel = '[tmp]';

    if ($includeWatermark && watermark_auto_apply_enabled()) {
        $watermarkPath = resolve_configured_watermark_path();
        if ($watermarkPath && realpath($watermarkPath) !== realpath($overlayImagePath)) {
            $filterComplex .= ';[2:v]scale=iw*14/100:-1,colorchannelmixer=aa=0.85[wm];[tmp][wm]overlay=W-w-16:H-h-16[outv]';
            $inputArgs .= ' -i ' . escapeshellarg($watermarkPath);
            $finalLabel = '[outv]';
        }
    }

    $cmd = sprintf(
        '%s -y %s -filter_complex %s -map %s -map 0:a? -c:v libx264 -crf 20 -preset fast -c:a copy -movflags +faststart %s 2>&1',
        ffmpeg_bin(),
        $inputArgs,
        escapeshellarg($filterComplex),
        escapeshellarg($finalLabel),
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

function gd_load_image(string $path): ?array
{
    $info = @getimagesize($path);
    if (!$info) {
        return null;
    }

    $mime = strtolower((string) ($info['mime'] ?? ''));
    $img = match ($mime) {
        'image/png'  => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
        'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
        'image/gif'  => function_exists('imagecreatefromgif') ? @imagecreatefromgif($path) : false,
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default      => false,
    };

    if (!$img) {
        return null;
    }

    return ['img' => $img, 'mime' => $mime];
}

function gd_apply_global_opacity(GdImage $img, float $opacity): void
{
    $opacity = max(0.0, min(1.0, $opacity));
    if ($opacity >= 0.999) {
        return;
    }

    $w = imagesx($img);
    $h = imagesy($img);
    for ($x = 0; $x < $w; $x++) {
        for ($y = 0; $y < $h; $y++) {
            $rgba = imagecolorsforindex($img, imagecolorat($img, $x, $y));
            $alpha0 = (int) ($rgba['alpha'] ?? 0);
            $alpha1 = (int) round(127 - ((127 - $alpha0) * $opacity));
            $alpha1 = max(0, min(127, $alpha1));
            $color = imagecolorallocatealpha($img, $rgba['red'], $rgba['green'], $rgba['blue'], $alpha1);
            imagesetpixel($img, $x, $y, $color);
        }
    }
}

function apply_overlay_to_image(
    string $inputImagePath,
    string $overlayImagePath,
    string $outputPath,
    string $position    = 'bottom-right',
    float  $opacity     = 1.0,
    int    $scalePercent = 30
): array {
    if (!is_file($inputImagePath)) {
        return ['ok' => false, 'error' => 'Input image not found: ' . $inputImagePath];
    }
    if (!is_file($overlayImagePath)) {
        return ['ok' => false, 'error' => 'Overlay image not found: ' . $overlayImagePath];
    }
    if (!function_exists('imagecreatetruecolor')) {
        return ['ok' => false, 'error' => 'GD extension is not available'];
    }

    $baseData = gd_load_image($inputImagePath);
    $ovrData  = gd_load_image($overlayImagePath);
    if (!$baseData || !$ovrData) {
        return ['ok' => false, 'error' => 'Unsupported image format'];
    }

    $base = $baseData['img'];
    $ovr = $ovrData['img'];
    imagealphablending($base, true);
    imagesavealpha($base, true);
    imagealphablending($ovr, true);
    imagesavealpha($ovr, true);

    $baseW = imagesx($base);
    $baseH = imagesy($base);
    $ovrW  = imagesx($ovr);
    $ovrH  = imagesy($ovr);
    if ($ovrW <= 0 || $ovrH <= 0) {
        imagedestroy($base);
        imagedestroy($ovr);
        return ['ok' => false, 'error' => 'Overlay image dimensions are invalid'];
    }

    if ($position === 'full') {
        $drawW = $baseW;
        $drawH = $baseH;
    } else {
        $drawW = (int) max(1, round($baseW * max(5, min(100, $scalePercent)) / 100));
        $drawH = (int) max(1, round($drawW * ($ovrH / $ovrW)));
    }

    $resized = imagecreatetruecolor($drawW, $drawH);
    imagealphablending($resized, false);
    imagesavealpha($resized, true);
    $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
    imagefilledrectangle($resized, 0, 0, $drawW, $drawH, $transparent);
    imagecopyresampled($resized, $ovr, 0, 0, 0, 0, $drawW, $drawH, $ovrW, $ovrH);
    gd_apply_global_opacity($resized, $opacity);

    $positions = [
        'top-left'     => [10, 10],
        'top-right'    => [$baseW - $drawW - 10, 10],
        'bottom-left'  => [10, $baseH - $drawH - 10],
        'bottom-right' => [$baseW - $drawW - 10, $baseH - $drawH - 10],
        'center'       => [($baseW - $drawW) / 2, ($baseH - $drawH) / 2],
        'full'         => [0, 0],
    ];
    [$x, $y] = $positions[$position] ?? $positions['bottom-right'];
    imagecopy($base, $resized, (int) round($x), (int) round($y), 0, 0, $drawW, $drawH);

    $outDir = dirname($outputPath);
    if (!is_dir($outDir)) {
        mkdir($outDir, 0755, true);
    }

    $ext = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));
    $saved = match ($ext) {
        'jpg', 'jpeg' => imagejpeg($base, $outputPath, 90),
        'gif' => imagegif($base, $outputPath),
        'webp' => function_exists('imagewebp') ? imagewebp($base, $outputPath, 90) : false,
        default => imagepng($base, $outputPath),
    };

    imagedestroy($base);
    imagedestroy($ovr);
    imagedestroy($resized);

    if (!$saved) {
        return ['ok' => false, 'error' => 'Failed to write overlay image output'];
    }

    return ['ok' => true, 'output' => $outputPath, 'command' => 'gd:image-composite'];
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

    $ext = strtolower(pathinfo($inputAbs, PATHINFO_EXTENSION));
    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);

    if ($isImage) {
        $outSubdir  = 'images/processed';
        $outDir     = $uploadBase . '/' . $outSubdir;
        $safeExt    = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? $ext : 'png';
        $outFile    = 'ptmd_overlay_' . $item['id'] . '_' . time() . '.' . $safeExt;
        $outAbs     = $outDir . '/' . $outFile;
        $outRel     = $outSubdir . '/' . $outFile;
        $result = apply_overlay_to_image(
            $inputAbs,
            $overlayAbs,
            $outAbs,
            $job['position'],
            (float) $job['opacity'],
            (int) $job['scale']
        );
    } else {
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
    }

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
         SET done_items = (SELECT COUNT(*) FROM overlay_batch_items WHERE batch_job_id = :jid AND status IN ("done","failed")),
             updated_at = NOW()
         WHERE id = :jid'
    )->execute(['jid' => $job['id']]);
}

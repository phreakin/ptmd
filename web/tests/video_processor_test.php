<?php

declare(strict_types=1);

$ptmdTestFailures = $ptmdTestFailures ?? [];
$ptmdAssertions = $ptmdAssertions ?? 0;

if (!function_exists('ptmd_assert_true')) {
    function ptmd_assert_true(bool $condition, string $message): void
    {
        global $ptmdTestFailures, $ptmdAssertions;
        $ptmdAssertions++;
        if (!$condition) {
            $ptmdTestFailures[] = $message;
        }
    }
}

if (!function_exists('ptmd_assert_same')) {
    function ptmd_assert_same(mixed $actual, mixed $expected, string $message): void
    {
        ptmd_assert_true($actual === $expected, $message . ' (expected: ' . var_export($expected, true) . ', actual: ' . var_export($actual, true) . ')');
    }
}

if (!function_exists('get_db')) {
    function get_db(): ?PDO
    {
        return null;
    }
}

// functions.php provides site_setting() which video_processor.php depends on.
if (!function_exists('site_setting')) {
    require_once __DIR__ . '/../inc/functions.php';
}

require_once __DIR__ . '/../inc/video_processor.php';

// ---------------------------------------------------------------------------
// probe_video() — returns null immediately for a non-existent file
// ---------------------------------------------------------------------------

$probeResult = probe_video('/tmp/ptmd_test_nonexistent_video_xyz.mp4');
ptmd_assert_same($probeResult, null, 'probe_video() returns null when file does not exist');

// ---------------------------------------------------------------------------
// apply_overlay_to_video() — early-return error paths (no FFmpeg needed)
// ---------------------------------------------------------------------------

// Both files absent: input check fires first
$r1 = apply_overlay_to_video(
    '/tmp/ptmd_test_nonexistent_video.mp4',
    '/tmp/ptmd_test_nonexistent_overlay.png',
    '/tmp/ptmd_test_out.mp4'
);
ptmd_assert_same($r1['ok'] ?? true, false, 'apply_overlay_to_video() returns ok=false when input video is absent');
ptmd_assert_true(
    str_contains($r1['error'] ?? '', 'Input video not found'),
    'apply_overlay_to_video() error mentions "Input video not found" for missing input'
);

// Input video exists, overlay image missing
$dummyVideo = tempnam(sys_get_temp_dir(), 'ptmd_vid_');
$r2 = apply_overlay_to_video(
    $dummyVideo,
    '/tmp/ptmd_test_nonexistent_overlay.png',
    '/tmp/ptmd_test_out.mp4'
);
ptmd_assert_same($r2['ok'] ?? true, false, 'apply_overlay_to_video() returns ok=false when overlay image is absent');
ptmd_assert_true(
    str_contains($r2['error'] ?? '', 'Overlay image not found'),
    'apply_overlay_to_video() error mentions "Overlay image not found" for missing overlay'
);
@unlink($dummyVideo);

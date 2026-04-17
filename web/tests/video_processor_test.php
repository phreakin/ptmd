<?php

declare(strict_types=1);

$ptmdTestFailures = $ptmdTestFailures ?? [];
$ptmdAssertions   = $ptmdAssertions   ?? 0;

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
        ptmd_assert_true(
            $actual === $expected,
            $message . ' (expected: ' . var_export($expected, true) . ', actual: ' . var_export($actual, true) . ')'
        );
    }
}

// Stub out DB and settings functions that video_processor.php depends on
if (!function_exists('get_db')) {
    function get_db(): ?PDO { return null; }
}
if (!function_exists('site_setting')) {
    function site_setting(string $key, string $fallback = ''): string { return $fallback; }
}

// Fake DOCUMENT_ROOT so path resolution in vp_is_safe_path works headlessly
$_SERVER['DOCUMENT_ROOT'] = '/tmp/ptmd_test_docroot';

require_once __DIR__ . '/../inc/video_processor.php';

// ── vp_is_safe_path ───────────────────────────────────────────────────────────

ptmd_assert_true(
    vp_is_safe_path('/uploads/clips/foo.mp4'),
    'vp_is_safe_path: accepts /uploads/ prefix'
);

ptmd_assert_true(
    vp_is_safe_path('/assets/brand/overlays/logo.png'),
    'vp_is_safe_path: accepts /assets/brand/ prefix'
);

ptmd_assert_true(
    !vp_is_safe_path('/etc/passwd'),
    'vp_is_safe_path: rejects /etc/passwd'
);

ptmd_assert_true(
    !vp_is_safe_path('../../etc/shadow'),
    'vp_is_safe_path: rejects traversal attempt'
);

ptmd_assert_true(
    !vp_is_safe_path('/var/www/secret.php'),
    'vp_is_safe_path: rejects arbitrary /var/www path'
);

ptmd_assert_true(
    !vp_is_safe_path(''),
    'vp_is_safe_path: rejects empty string'
);

// Traversal stripped then fails prefix check
ptmd_assert_true(
    !vp_is_safe_path('/uploads/../etc/passwd'),
    'vp_is_safe_path: strips ../ traversal then rejects'
);

// ── build_image_layer_filter ──────────────────────────────────────────────────

// Basic layer — bottom-right, full opacity, no time window
$layer = ['path' => '/uploads/logo.png', 'position' => 'bottom-right', 'scale' => 30, 'opacity' => 1.0];
$filter = build_image_layer_filter(1, '[0:v]', '[v0]', $layer);

ptmd_assert_true(
    str_contains($filter, '[1:v]'),
    'build_image_layer_filter: includes correct input stream index'
);
ptmd_assert_true(
    str_contains($filter, 'scale=iw*30/100:-1'),
    'build_image_layer_filter: includes scale filter at 30%'
);
ptmd_assert_true(
    str_contains($filter, 'W-w-10:H-h-10'),
    'build_image_layer_filter: bottom-right position expression'
);
ptmd_assert_true(
    !str_contains($filter, 'colorchannelmixer'),
    'build_image_layer_filter: no opacity filter when opacity=1.0'
);

// With opacity < 1
$layerOpacity = ['path' => '/uploads/logo.png', 'position' => 'center', 'scale' => 50, 'opacity' => 0.5];
$filterOpacity = build_image_layer_filter(2, '[v0]', '[v1]', $layerOpacity);
ptmd_assert_true(
    str_contains($filterOpacity, 'colorchannelmixer=aa=0.50'),
    'build_image_layer_filter: adds opacity filter when opacity < 1.0'
);
ptmd_assert_true(
    str_contains($filterOpacity, '(W-w)/2:(H-h)/2'),
    'build_image_layer_filter: center position expression'
);

// With time window (start + end)
$layerTimed = ['position' => 'top-left', 'scale' => 20, 'opacity' => 1.0, 'start_sec' => 2.5, 'end_sec' => 10.0];
$filterTimed = build_image_layer_filter(3, '[v1]', '[v2]', $layerTimed);
ptmd_assert_true(
    str_contains($filterTimed, "between(t,2.500,10.000)"),
    'build_image_layer_filter: time window uses between() enable expression'
);

// With only start_sec
$layerStart = ['position' => 'top-right', 'scale' => 25, 'opacity' => 1.0, 'start_sec' => 5.0];
$filterStart = build_image_layer_filter(4, '[v2]', '[v3]', $layerStart);
ptmd_assert_true(
    str_contains($filterStart, "gte(t,5.000)"),
    'build_image_layer_filter: start-only window uses gte() enable expression'
);

// With only end_sec
$layerEnd = ['position' => 'bottom-left', 'scale' => 25, 'opacity' => 1.0, 'end_sec' => 8.0];
$filterEnd = build_image_layer_filter(5, '[v3]', '[v4]', $layerEnd);
ptmd_assert_true(
    str_contains($filterEnd, "lte(t,8.000)"),
    'build_image_layer_filter: end-only window uses lte() enable expression'
);

// Full position uses scale=iw:ih
$layerFull = ['position' => 'full', 'scale' => 100, 'opacity' => 1.0];
$filterFull = build_image_layer_filter(6, '[v4]', '[vout]', $layerFull);
ptmd_assert_true(
    str_contains($filterFull, 'scale=iw:ih'),
    'build_image_layer_filter: full position uses iw:ih scale'
);

// Scale is clamped to 1–100
$layerScale = ['position' => 'center', 'scale' => 200, 'opacity' => 1.0];
$filterScale = build_image_layer_filter(7, '[0:v]', '[vout]', $layerScale);
ptmd_assert_true(
    str_contains($filterScale, 'scale=iw*100/100:-1'),
    'build_image_layer_filter: scale is clamped to 100'
);

// ── burn_caption_to_video (input-not-found path) ──────────────────────────────
$captionResult = burn_caption_to_video(
    '/tmp/nonexistent_video_ptmd_test.mp4',
    'Hello world caption',
    '/tmp/ptmd_caption_out.mp4'
);
ptmd_assert_same(
    $captionResult['ok'],
    false,
    'burn_caption_to_video: returns ok=false when input file missing'
);
ptmd_assert_true(
    str_contains((string) ($captionResult['error'] ?? ''), 'not found'),
    'burn_caption_to_video: error message mentions not found'
);

// ── apply_multi_layer_composition (input-not-found path) ─────────────────────
$compositionResult = apply_multi_layer_composition(
    '/tmp/nonexistent_video_ptmd_test.mp4',
    [['path' => '/uploads/logo.png', 'position' => 'bottom-right', 'scale' => 30, 'opacity' => 1.0]],
    '/tmp/ptmd_composition_out.mp4'
);
ptmd_assert_same(
    $compositionResult['ok'],
    false,
    'apply_multi_layer_composition: returns ok=false when input video missing'
);

// ── apply_multi_layer_composition (empty layers) ─────────────────────────────
$emptyLayersResult = apply_multi_layer_composition(
    '/tmp/nonexistent_video_ptmd_test.mp4',
    [],
    '/tmp/ptmd_empty_layers_out.mp4'
);
ptmd_assert_same(
    $emptyLayersResult['ok'],
    false,
    'apply_multi_layer_composition: returns ok=false when no layers provided'
);

// ── apply_multi_layer_composition (unsafe layer path) ────────────────────────
// Use a real file for the video input stub check — create a temp placeholder
$fakeVideo = '/tmp/ptmd_fake_video_' . getmypid() . '.mp4';
file_put_contents($fakeVideo, '');

$unsafeLayerResult = apply_multi_layer_composition(
    $fakeVideo,
    [['path' => '/etc/passwd', 'position' => 'center', 'scale' => 30, 'opacity' => 1.0]],
    '/tmp/ptmd_unsafe_layer_out.mp4'
);
ptmd_assert_same(
    $unsafeLayerResult['ok'],
    false,
    'apply_multi_layer_composition: rejects unsafe layer path'
);
ptmd_assert_true(
    str_contains((string) ($unsafeLayerResult['error'] ?? ''), 'Unsafe layer path'),
    'apply_multi_layer_composition: error message identifies unsafe path'
);

@unlink($fakeVideo);

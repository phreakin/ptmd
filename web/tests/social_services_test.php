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

require_once __DIR__ . '/../inc/social_services.php';

$baseItem = [
    'id' => 42,
    'platform' => 'YouTube',
];

$dispatchCases = [
    ['platform' => 'YouTube', 'expected_error' => 'TODO: YouTube API integration not configured.'],
    ['platform' => 'YouTube Shorts', 'expected_error' => 'TODO: YouTube Shorts API integration not configured.'],
    ['platform' => 'TikTok', 'expected_error' => 'TODO: TikTok API integration not configured.'],
    ['platform' => 'Instagram Reels', 'expected_error' => 'TODO: Instagram Reels (Meta Graph API) not configured.'],
    ['platform' => 'Facebook Reels', 'expected_error' => 'TODO: Facebook Reels (Meta Graph API) not configured.'],
    ['platform' => 'X', 'expected_error' => 'TODO: X (Twitter) API v2 integration not configured.'],
];

foreach ($dispatchCases as $case) {
    $item = $baseItem;
    $item['platform'] = $case['platform'];
    $result = dispatch_social_post($item);
    ptmd_assert_same($result['ok'] ?? null, false, "dispatch_social_post returns failure for {$case['platform']}");
    ptmd_assert_true(array_key_exists('external_post_id', $result), "dispatch_social_post includes external_post_id for {$case['platform']}");
    ptmd_assert_same($result['external_post_id'], null, "dispatch_social_post keeps external_post_id null for {$case['platform']}");
    ptmd_assert_same($result['error'] ?? null, $case['expected_error'], "dispatch_social_post returns expected error for {$case['platform']}");
}

$unknownResult = dispatch_social_post(['id' => 77, 'platform' => 'LinkedIn']);
ptmd_assert_same($unknownResult['ok'] ?? null, false, 'dispatch_social_post returns failure for unknown platform');
ptmd_assert_true(str_contains((string) ($unknownResult['error'] ?? ''), 'Unknown platform'), 'dispatch_social_post returns unknown platform message');

$stubCalls = [
    ['name' => 'post_to_youtube', 'result' => post_to_youtube($baseItem), 'expected' => 'TODO: YouTube API integration not configured.'],
    ['name' => 'post_to_youtube_shorts', 'result' => post_to_youtube_shorts($baseItem), 'expected' => 'TODO: YouTube Shorts API integration not configured.'],
    ['name' => 'post_to_tiktok', 'result' => post_to_tiktok($baseItem), 'expected' => 'TODO: TikTok API integration not configured.'],
    ['name' => 'post_to_instagram_reels', 'result' => post_to_instagram_reels($baseItem), 'expected' => 'TODO: Instagram Reels (Meta Graph API) not configured.'],
    ['name' => 'post_to_facebook_reels', 'result' => post_to_facebook_reels($baseItem), 'expected' => 'TODO: Facebook Reels (Meta Graph API) not configured.'],
    ['name' => 'post_to_x', 'result' => post_to_x($baseItem), 'expected' => 'TODO: X (Twitter) API v2 integration not configured.'],
];

foreach ($stubCalls as $stub) {
    ptmd_assert_same($stub['result']['ok'] ?? null, false, "{$stub['name']} returns failure");
    ptmd_assert_true(array_key_exists('external_post_id', $stub['result']), "{$stub['name']} includes external_post_id");
    ptmd_assert_same($stub['result']['external_post_id'], null, "{$stub['name']} keeps external_post_id null");
    ptmd_assert_same($stub['result']['error'] ?? null, $stub['expected'], "{$stub['name']} returns expected TODO error");
}

// ── get_social_image_requirements ────────────────────────────────────────────

$reqs = get_social_image_requirements();
ptmd_assert_true(is_array($reqs), 'get_social_image_requirements returns an array');
ptmd_assert_true(array_key_exists('YouTube', $reqs), 'get_social_image_requirements includes YouTube');
ptmd_assert_true(array_key_exists('thumbnail', $reqs['YouTube']), 'YouTube requirements include thumbnail');

$ytThumb = $reqs['YouTube']['thumbnail'];
ptmd_assert_same($ytThumb['recommended_width'],  1280, 'YouTube thumbnail recommended_width is 1280');
ptmd_assert_same($ytThumb['recommended_height'], 720,  'YouTube thumbnail recommended_height is 720');
ptmd_assert_same($ytThumb['aspect_ratio_w'],     16,   'YouTube thumbnail aspect_ratio_w is 16');
ptmd_assert_same($ytThumb['aspect_ratio_h'],     9,    'YouTube thumbnail aspect_ratio_h is 9');
ptmd_assert_same($ytThumb['max_file_size'],      2 * 1024 * 1024, 'YouTube thumbnail max_file_size is 2 MB');

foreach (['YouTube Shorts', 'TikTok', 'Instagram Reels', 'Facebook Reels'] as $platform) {
    ptmd_assert_true(array_key_exists($platform, $reqs), "get_social_image_requirements includes {$platform}");
    ptmd_assert_true(array_key_exists('cover', $reqs[$platform]), "{$platform} requirements include cover");
}
ptmd_assert_true(array_key_exists('X', $reqs), 'get_social_image_requirements includes X');

// ── validate_social_image ─────────────────────────────────────────────────────

// Exact match — should be valid
$result = validate_social_image('YouTube', 'thumbnail', 1280, 720, 1024 * 1024);
ptmd_assert_same($result['is_valid'], true, 'validate_social_image: YouTube thumbnail exact dimensions is valid');
ptmd_assert_same($result['errors'], [], 'validate_social_image: YouTube thumbnail exact has no errors');

// Wrong dimensions — should be invalid
$result = validate_social_image('YouTube', 'thumbnail', 640, 480, 500 * 1024);
ptmd_assert_same($result['is_valid'], false, 'validate_social_image: YouTube thumbnail wrong dimensions is invalid');
ptmd_assert_true(!empty($result['errors']), 'validate_social_image: YouTube thumbnail wrong dimensions has errors');

// File too large — should be invalid
$result = validate_social_image('YouTube', 'thumbnail', 1280, 720, 3 * 1024 * 1024);
ptmd_assert_same($result['is_valid'], false, 'validate_social_image: YouTube thumbnail oversized file is invalid');
ptmd_assert_true(!empty($result['errors']), 'validate_social_image: YouTube thumbnail oversized file has errors');

// Unknown platform — no requirements defined, treated as valid
$result = validate_social_image('LinkedIn', 'thumbnail', 1200, 627, 1024 * 1024);
ptmd_assert_same($result['is_valid'], true, 'validate_social_image: unknown platform returns valid (no requirements)');
ptmd_assert_same($result['errors'], [], 'validate_social_image: unknown platform has no errors');

// Unknown image type on known platform — treated as valid
$result = validate_social_image('YouTube', 'story', 1080, 1920, 1024 * 1024);
ptmd_assert_same($result['is_valid'], true, 'validate_social_image: undefined image type returns valid');

// Null dimensions — should flag error
$result = validate_social_image('YouTube', 'thumbnail', null, null, 500 * 1024);
ptmd_assert_same($result['is_valid'], false, 'validate_social_image: null dimensions is invalid');
ptmd_assert_true(!empty($result['errors']), 'validate_social_image: null dimensions has errors');

// Instagram Reels cover — correct 9:16
$result = validate_social_image('Instagram Reels', 'cover', 1080, 1920, 4 * 1024 * 1024);
ptmd_assert_same($result['is_valid'], true, 'validate_social_image: Instagram Reels cover exact is valid');

// Instagram Reels cover — file too large (>8 MB)
$result = validate_social_image('Instagram Reels', 'cover', 1080, 1920, 9 * 1024 * 1024);
ptmd_assert_same($result['is_valid'], false, 'validate_social_image: Instagram Reels cover oversized is invalid');

// X banner — correct 3:1
$result = validate_social_image('X', 'banner', 1500, 500, 1024 * 1024);
ptmd_assert_same($result['is_valid'], true, 'validate_social_image: X banner correct dimensions is valid');

// Aspect ratio mismatch (wrong ratio, not matching recommended size either)
$result = validate_social_image('X', 'thumbnail', 800, 800, 500 * 1024);
ptmd_assert_same($result['is_valid'], false, 'validate_social_image: X thumbnail 1:1 aspect ratio is invalid (expects 16:9)');
ptmd_assert_true(!empty($result['errors']), 'validate_social_image: X thumbnail 1:1 has errors');

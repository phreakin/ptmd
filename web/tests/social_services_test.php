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
    ptmd_assert_same($result['external_post_id'] ?? 'missing', null, "dispatch_social_post keeps external_post_id null for {$case['platform']}");
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
    ptmd_assert_same($stub['result']['external_post_id'] ?? 'missing', null, "{$stub['name']} keeps external_post_id null");
    ptmd_assert_same($stub['result']['error'] ?? null, $stub['expected'], "{$stub['name']} returns expected TODO error");
}

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

// ---------------------------------------------------------------------------
// Dispatch contract: all 8 platforms return the expected stub error
// ---------------------------------------------------------------------------

$dispatchCases = [
    ['platform' => 'YouTube',             'expected_error' => 'TODO: YouTube API integration not configured.'],
    ['platform' => 'YouTube Shorts',      'expected_error' => 'TODO: YouTube Shorts API integration not configured.'],
    ['platform' => 'TikTok',              'expected_error' => 'TODO: TikTok API integration not configured.'],
    ['platform' => 'Instagram Reels',     'expected_error' => 'TODO: Instagram Reels (Meta Graph API) not configured.'],
    ['platform' => 'Facebook Reels',      'expected_error' => 'TODO: Facebook Reels (Meta Graph API) not configured.'],
    ['platform' => 'Snapchat Spotlight',  'expected_error' => 'TODO: Snapchat Spotlight API integration not configured.'],
    ['platform' => 'X',                   'expected_error' => 'TODO: X (Twitter) API v2 integration not configured.'],
    ['platform' => 'Pinterest Idea Pins', 'expected_error' => 'TODO: Pinterest Idea Pins API integration not configured.'],
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

// ---------------------------------------------------------------------------
// Unknown platform
// ---------------------------------------------------------------------------

$unknownResult = dispatch_social_post(['id' => 77, 'platform' => 'LinkedIn']);
ptmd_assert_same($unknownResult['ok'] ?? null, false, 'dispatch_social_post returns failure for unknown platform');
ptmd_assert_true(str_contains((string) ($unknownResult['error'] ?? ''), 'Unknown platform'), 'dispatch_social_post returns unknown platform message');

// ---------------------------------------------------------------------------
// Idempotency guard: already-posted items are not re-dispatched
// ---------------------------------------------------------------------------

$postedItem = ['id' => 99, 'platform' => 'YouTube', 'status' => 'posted', 'external_post_id' => 'yt_abc123'];
$idempotentResult = dispatch_social_post($postedItem);
ptmd_assert_same($idempotentResult['ok'] ?? null, true, 'dispatch_social_post returns ok for already-posted item (idempotency)');
ptmd_assert_same($idempotentResult['external_post_id'] ?? null, 'yt_abc123', 'dispatch_social_post returns existing external_post_id for posted item');
ptmd_assert_same($idempotentResult['error'] ?? null, null, 'dispatch_social_post returns no error for posted item');

// ---------------------------------------------------------------------------
// Platform function stubs: direct calls
// ---------------------------------------------------------------------------

$stubCalls = [
    ['name' => 'post_to_youtube',             'result' => post_to_youtube($baseItem),             'expected' => 'TODO: YouTube API integration not configured.'],
    ['name' => 'post_to_youtube_shorts',      'result' => post_to_youtube_shorts($baseItem),      'expected' => 'TODO: YouTube Shorts API integration not configured.'],
    ['name' => 'post_to_tiktok',              'result' => post_to_tiktok($baseItem),              'expected' => 'TODO: TikTok API integration not configured.'],
    ['name' => 'post_to_instagram_reels',     'result' => post_to_instagram_reels($baseItem),     'expected' => 'TODO: Instagram Reels (Meta Graph API) not configured.'],
    ['name' => 'post_to_facebook_reels',      'result' => post_to_facebook_reels($baseItem),      'expected' => 'TODO: Facebook Reels (Meta Graph API) not configured.'],
    ['name' => 'post_to_snapchat_spotlight',  'result' => post_to_snapchat_spotlight($baseItem),  'expected' => 'TODO: Snapchat Spotlight API integration not configured.'],
    ['name' => 'post_to_x',                   'result' => post_to_x($baseItem),                   'expected' => 'TODO: X (Twitter) API v2 integration not configured.'],
    ['name' => 'post_to_pinterest_idea_pins', 'result' => post_to_pinterest_idea_pins($baseItem), 'expected' => 'TODO: Pinterest Idea Pins API integration not configured.'],
];

foreach ($stubCalls as $stub) {
    ptmd_assert_same($stub['result']['ok'] ?? null, false, "{$stub['name']} returns failure");
    ptmd_assert_true(array_key_exists('external_post_id', $stub['result']), "{$stub['name']} includes external_post_id");
    ptmd_assert_same($stub['result']['external_post_id'], null, "{$stub['name']} keeps external_post_id null");
    ptmd_assert_same($stub['result']['error'] ?? null, $stub['expected'], "{$stub['name']} returns expected TODO error");
}

// ---------------------------------------------------------------------------
// classify_dispatch_error
// ---------------------------------------------------------------------------

ptmd_assert_same(classify_dispatch_error('Token expired'), PTMD_ERR_AUTH, 'classify_dispatch_error: token → auth');
ptmd_assert_same(classify_dispatch_error('401 Unauthorized'), PTMD_ERR_AUTH, 'classify_dispatch_error: unauthorized → auth');
ptmd_assert_same(classify_dispatch_error('403 Forbidden'), PTMD_ERR_AUTH, 'classify_dispatch_error: forbidden → auth');
ptmd_assert_same(classify_dispatch_error('rate limit exceeded'), PTMD_ERR_RATE_LIMIT, 'classify_dispatch_error: rate limit → rate_limit');
ptmd_assert_same(classify_dispatch_error('429 Too Many Requests'), PTMD_ERR_RATE_LIMIT, 'classify_dispatch_error: 429 → rate_limit');
ptmd_assert_same(classify_dispatch_error('Policy violation detected'), PTMD_ERR_POLICY, 'classify_dispatch_error: policy → policy');
ptmd_assert_same(classify_dispatch_error('Content banned'), PTMD_ERR_POLICY, 'classify_dispatch_error: banned → policy');
ptmd_assert_same(classify_dispatch_error('503 Service Unavailable'), PTMD_ERR_TRANSIENT, 'classify_dispatch_error: 503 → transient');
ptmd_assert_same(classify_dispatch_error('Connection timed out'), PTMD_ERR_TRANSIENT, 'classify_dispatch_error: timeout → transient');
ptmd_assert_same(classify_dispatch_error('Some unknown error'), PTMD_ERR_UNKNOWN, 'classify_dispatch_error: unknown → unknown');

// ---------------------------------------------------------------------------
// _ptmd_correlation_id returns expected format
// ---------------------------------------------------------------------------

$corrId = _ptmd_correlation_id(42);
ptmd_assert_true(str_starts_with($corrId, 'ptmd-q42-'), '_ptmd_correlation_id returns expected prefix');
ptmd_assert_same(strlen($corrId), 15, '_ptmd_correlation_id returns expected total length'); // "ptmd-q42-" (9) + 6 hex chars


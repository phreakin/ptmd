<?php

declare(strict_types=1);

// get_db() is already stubbed (returns null) by social_services_test.php,
// which run.php requires before this file.

require_once __DIR__ . '/../inc/functions.php';

// ---------------------------------------------------------------------------
// ptmd_platform_to_site_key() — normalization helper
// ---------------------------------------------------------------------------

ptmd_assert_same(
    ptmd_platform_to_site_key('YouTube'),
    'youtube',
    'ptmd_platform_to_site_key lowercases a simple name'
);

ptmd_assert_same(
    ptmd_platform_to_site_key('YouTube Shorts'),
    'youtube_shorts',
    'ptmd_platform_to_site_key converts spaces to underscores'
);

ptmd_assert_same(
    ptmd_platform_to_site_key('Instagram Reels'),
    'instagram_reels',
    'ptmd_platform_to_site_key handles two-word names'
);

ptmd_assert_same(
    ptmd_platform_to_site_key('Facebook Reels'),
    'facebook_reels',
    'ptmd_platform_to_site_key handles Facebook Reels'
);

ptmd_assert_same(
    ptmd_platform_to_site_key('TikTok'),
    'tiktok',
    'ptmd_platform_to_site_key lowercases TikTok'
);

ptmd_assert_same(
    ptmd_platform_to_site_key('X'),
    'x',
    'ptmd_platform_to_site_key lowercases X'
);

ptmd_assert_same(
    ptmd_platform_to_site_key('  YouTube Shorts  '),
    'youtube_shorts',
    'ptmd_platform_to_site_key trims whitespace'
);

ptmd_assert_same(
    ptmd_platform_to_site_key('youtube_shorts'),
    'youtube_shorts',
    'ptmd_platform_to_site_key is idempotent on already-normalised keys'
);

// ---------------------------------------------------------------------------
// get_posting_sites() — returns empty array when DB unavailable
// ---------------------------------------------------------------------------

$sites = get_posting_sites(true);
ptmd_assert_true(is_array($sites), 'get_posting_sites returns an array when DB is unavailable');
ptmd_assert_same(count($sites), 0, 'get_posting_sites returns empty array when DB is unavailable');

$allSites = get_posting_sites(false);
ptmd_assert_true(is_array($allSites), 'get_posting_sites(false) returns an array when DB is unavailable');

// ---------------------------------------------------------------------------
// dispatch_social_post() — site-key registry via PTMD_SITE_DISPATCH_REGISTRY
// ---------------------------------------------------------------------------

// All 6 known site keys must be present in the registry
$expectedKeys = ['youtube', 'youtube_shorts', 'tiktok', 'instagram_reels', 'facebook_reels', 'x'];
foreach ($expectedKeys as $key) {
    ptmd_assert_true(
        array_key_exists($key, PTMD_SITE_DISPATCH_REGISTRY),
        "PTMD_SITE_DISPATCH_REGISTRY contains key: {$key}"
    );
}

// Dispatch accepts pre-normalised site_key format as well as display names
$siteKeyItem = ['id' => 99, 'platform' => 'youtube_shorts'];
$result = dispatch_social_post($siteKeyItem);
ptmd_assert_same($result['ok'] ?? null, false, 'dispatch_social_post handles site_key format (youtube_shorts)');
ptmd_assert_same(
    $result['error'] ?? null,
    'TODO: YouTube Shorts API integration not configured.',
    'dispatch_social_post routes youtube_shorts key to correct handler'
);

// Display-name format still works (backward compatibility)
$displayNameItem = ['id' => 99, 'platform' => 'YouTube Shorts'];
$result2 = dispatch_social_post($displayNameItem);
ptmd_assert_same($result2['ok'] ?? null, false, 'dispatch_social_post handles display-name format (YouTube Shorts)');
ptmd_assert_same(
    $result2['error'] ?? null,
    'TODO: YouTube Shorts API integration not configured.',
    'dispatch_social_post routes display-name YouTube Shorts to correct handler'
);

// Unknown platform returns a clear error
$unknownItem = ['id' => 100, 'platform' => 'Pinterest'];
$unknownResult = dispatch_social_post($unknownItem);
ptmd_assert_same($unknownResult['ok'] ?? null, false, 'dispatch_social_post returns failure for unknown platform');
ptmd_assert_true(
    str_contains((string) ($unknownResult['error'] ?? ''), 'Unknown platform'),
    'dispatch_social_post error message mentions Unknown platform for Pinterest'
);
ptmd_assert_true(
    str_contains((string) ($unknownResult['error'] ?? ''), 'Pinterest'),
    'dispatch_social_post error message includes the unknown platform name'
);
ptmd_assert_true(
    array_key_exists('external_post_id', $unknownResult),
    'dispatch_social_post includes external_post_id key for unknown platform'
);
ptmd_assert_same(
    $unknownResult['external_post_id'],
    null,
    'dispatch_social_post sets external_post_id null for unknown platform'
);

// Empty platform string returns unknown-platform error
$emptyResult = dispatch_social_post(['id' => 101, 'platform' => '']);
ptmd_assert_same($emptyResult['ok'] ?? null, false, 'dispatch_social_post returns failure for empty platform string');
ptmd_assert_true(
    str_contains((string) ($emptyResult['error'] ?? ''), 'Unknown platform'),
    'dispatch_social_post returns unknown platform error for empty string'
);

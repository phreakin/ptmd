<?php

declare(strict_types=1);

$ptmdTestFailures = $ptmdTestFailures ?? [];
$ptmdAssertions   = $ptmdAssertions   ?? 0;

require_once __DIR__ . '/../inc/social_platform_rules.php';

// ---------------------------------------------------------------------------
// PTMD_PLATFORMS constant — all 8 channels present and ordered
// ---------------------------------------------------------------------------

$expectedPlatforms = [
    'YouTube',
    'YouTube Shorts',
    'TikTok',
    'Instagram Reels',
    'Facebook Reels',
    'Snapchat Spotlight',
    'X',
    'Pinterest Idea Pins',
];

ptmd_assert_same(PTMD_PLATFORMS, $expectedPlatforms, 'PTMD_PLATFORMS lists all 8 platforms in correct order');

// ---------------------------------------------------------------------------
// get_platform_rules — returns rules for all 8 platforms
// ---------------------------------------------------------------------------

foreach (PTMD_PLATFORMS as $platform) {
    $rules = get_platform_rules($platform);
    ptmd_assert_true(!empty($rules), "get_platform_rules returns non-empty rules for {$platform}");
    ptmd_assert_true(array_key_exists('max_caption_length', $rules), "get_platform_rules includes max_caption_length for {$platform}");
    ptmd_assert_true(array_key_exists('phase', $rules), "get_platform_rules includes phase for {$platform}");
}

// Unknown platform returns empty array
ptmd_assert_same(get_platform_rules('LinkedIn'), [], 'get_platform_rules returns [] for unknown platform');

// Phase assignments
ptmd_assert_same(get_platform_rules('YouTube')['phase'],             1, 'YouTube is phase 1');
ptmd_assert_same(get_platform_rules('YouTube Shorts')['phase'],      1, 'YouTube Shorts is phase 1');
ptmd_assert_same(get_platform_rules('TikTok')['phase'],              1, 'TikTok is phase 1');
ptmd_assert_same(get_platform_rules('Instagram Reels')['phase'],     1, 'Instagram Reels is phase 1');
ptmd_assert_same(get_platform_rules('Facebook Reels')['phase'],      1, 'Facebook Reels is phase 1');
ptmd_assert_same(get_platform_rules('X')['phase'],                   1, 'X is phase 1');
ptmd_assert_same(get_platform_rules('Snapchat Spotlight')['phase'],  2, 'Snapchat Spotlight is phase 2');
ptmd_assert_same(get_platform_rules('Pinterest Idea Pins')['phase'], 2, 'Pinterest Idea Pins is phase 2');

// ---------------------------------------------------------------------------
// preflight_check_queue_item — missing/unknown platform
// ---------------------------------------------------------------------------

$result = preflight_check_queue_item([]);
ptmd_assert_same($result['ok'], false, 'preflight: missing platform fails');
ptmd_assert_true(!empty($result['errors']), 'preflight: missing platform returns errors');

$result = preflight_check_queue_item(['platform' => 'LinkedIn']);
ptmd_assert_same($result['ok'], false, 'preflight: unknown platform fails');

// ---------------------------------------------------------------------------
// preflight — caption length overflow
// ---------------------------------------------------------------------------

// TikTok: max_caption_length = 2200
$longCaption = str_repeat('a', 2201);
$result = preflight_check_queue_item(['platform' => 'TikTok', 'caption' => $longCaption]);
ptmd_assert_same($result['ok'], false, 'preflight: TikTok caption overflow fails');
ptmd_assert_true(!empty($result['errors']), 'preflight: TikTok caption overflow returns errors array');

// X: max_caption_length = 280
$xLongCaption = str_repeat('b', 281);
$result = preflight_check_queue_item(['platform' => 'X', 'caption' => $xLongCaption]);
ptmd_assert_same($result['ok'], false, 'preflight: X caption overflow fails');

// Snapchat: max_caption_length = 250
$snapCaption = str_repeat('c', 251);
$result = preflight_check_queue_item(['platform' => 'Snapchat Spotlight', 'caption' => $snapCaption]);
ptmd_assert_same($result['ok'], false, 'preflight: Snapchat caption overflow fails');

// Valid caption passes
$result = preflight_check_queue_item(['platform' => 'TikTok', 'caption' => 'Short caption.']);
ptmd_assert_same($result['ok'], true, 'preflight: valid TikTok caption passes');

// ---------------------------------------------------------------------------
// preflight — hashtag count overflow
// ---------------------------------------------------------------------------

// Instagram: max_hashtags = 30
$tooManyTags = implode(' ', array_map(static fn($i) => "#tag{$i}", range(1, 31)));
$result = preflight_check_queue_item(['platform' => 'Instagram Reels', 'caption' => $tooManyTags]);
ptmd_assert_same($result['ok'], false, 'preflight: Instagram Reels hashtag overflow fails');

// 30 tags is fine
$okTags = implode(' ', array_map(static fn($i) => "#tag{$i}", range(1, 30)));
$result = preflight_check_queue_item(['platform' => 'Instagram Reels', 'caption' => $okTags]);
ptmd_assert_same($result['ok'], true, 'preflight: Instagram Reels 30 hashtags passes');

// Snapchat: max_hashtags = 5
$tooManySnapTags = implode(' ', array_map(static fn($i) => "#snap{$i}", range(1, 6)));
$result = preflight_check_queue_item(['platform' => 'Snapchat Spotlight', 'caption' => $tooManySnapTags]);
ptmd_assert_same($result['ok'], false, 'preflight: Snapchat Spotlight hashtag overflow fails');

// ---------------------------------------------------------------------------
// preflight — duration violation
// ---------------------------------------------------------------------------

// YouTube Shorts: max_duration_sec = 60
$result = preflight_check_queue_item(['platform' => 'YouTube Shorts', 'caption' => 'ok', 'duration_sec' => 61]);
ptmd_assert_same($result['ok'], false, 'preflight: YouTube Shorts duration overflow fails');

$result = preflight_check_queue_item(['platform' => 'YouTube Shorts', 'caption' => 'ok', 'duration_sec' => 60]);
ptmd_assert_same($result['ok'], true, 'preflight: YouTube Shorts 60s duration passes');

// Snapchat: max_duration_sec = 60
$result = preflight_check_queue_item(['platform' => 'Snapchat Spotlight', 'caption' => 'ok', 'duration_sec' => 61]);
ptmd_assert_same($result['ok'], false, 'preflight: Snapchat Spotlight duration overflow fails');

// Pinterest: max_duration_sec = 60
$result = preflight_check_queue_item(['platform' => 'Pinterest Idea Pins', 'caption' => 'ok', 'duration_sec' => 61]);
ptmd_assert_same($result['ok'], false, 'preflight: Pinterest Idea Pins duration overflow fails');

// YouTube has no duration limit
$result = preflight_check_queue_item(['platform' => 'YouTube', 'caption' => 'ok', 'duration_sec' => 99999]);
ptmd_assert_same($result['ok'], true, 'preflight: YouTube has no duration limit');

// ---------------------------------------------------------------------------
// preflight — file size violation
// ---------------------------------------------------------------------------

// Snapchat: max_file_size_mb = 32
$result = preflight_check_queue_item(['platform' => 'Snapchat Spotlight', 'caption' => 'ok', 'file_size_mb' => 33]);
ptmd_assert_same($result['ok'], false, 'preflight: Snapchat Spotlight file size overflow fails');

$result = preflight_check_queue_item(['platform' => 'Snapchat Spotlight', 'caption' => 'ok', 'file_size_mb' => 32]);
ptmd_assert_same($result['ok'], true, 'preflight: Snapchat Spotlight 32 MB passes');

// ---------------------------------------------------------------------------
// preflight — required tags warning (YouTube Shorts needs #Shorts)
// ---------------------------------------------------------------------------

$result = preflight_check_queue_item(['platform' => 'YouTube Shorts', 'caption' => 'Great clip!']);
ptmd_assert_same($result['ok'], true, 'preflight: missing required tag is a warning, not error');
ptmd_assert_true(!empty($result['warnings']), 'preflight: missing #Shorts produces a warning');

$result = preflight_check_queue_item(['platform' => 'YouTube Shorts', 'caption' => 'Great clip! #Shorts']);
ptmd_assert_same($result['ok'], true, 'preflight: caption with #Shorts has no warnings');
ptmd_assert_same($result['warnings'], [], 'preflight: caption with #Shorts produces no warnings');

// ---------------------------------------------------------------------------
// preflight — title length (platforms with max_title_length)
// ---------------------------------------------------------------------------

// YouTube: max_title_length = 100
$result = preflight_check_queue_item(['platform' => 'YouTube', 'caption' => 'ok', 'title' => str_repeat('t', 101)]);
ptmd_assert_same($result['ok'], false, 'preflight: YouTube title overflow fails');

$result = preflight_check_queue_item(['platform' => 'YouTube', 'caption' => 'ok', 'title' => str_repeat('t', 100)]);
ptmd_assert_same($result['ok'], true, 'preflight: YouTube 100-char title passes');

// TikTok has no title field — title is ignored
$result = preflight_check_queue_item(['platform' => 'TikTok', 'caption' => 'ok', 'title' => str_repeat('t', 9999)]);
ptmd_assert_same($result['ok'], true, 'preflight: TikTok ignores title field');

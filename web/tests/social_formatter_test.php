<?php

declare(strict_types=1);

$ptmdTestFailures = $ptmdTestFailures ?? [];
$ptmdAssertions   = $ptmdAssertions   ?? 0;

require_once __DIR__ . '/../inc/social_formatter.php';

// ---------------------------------------------------------------------------
// normalize_hashtags
// ---------------------------------------------------------------------------

// Empty input
ptmd_assert_same(normalize_hashtags(''), '', 'normalize_hashtags: empty string returns empty');
ptmd_assert_same(normalize_hashtags('   '), '', 'normalize_hashtags: whitespace-only returns empty');

// Adds # prefix when missing
ptmd_assert_same(normalize_hashtags('shorts investigation'), '#shorts #investigation', 'normalize_hashtags: adds # to bare words');

// Preserves # when already present
ptmd_assert_same(normalize_hashtags('#shorts #investigation'), '#shorts #investigation', 'normalize_hashtags: preserves existing #');

// Deduplicates case-insensitively
ptmd_assert_same(normalize_hashtags('#Shorts #shorts'), '#Shorts', 'normalize_hashtags: deduplicates case-insensitively (keeps first)');

// Mixed with and without #
ptmd_assert_same(normalize_hashtags('shorts #Shorts'), '#shorts', 'normalize_hashtags: deduplicates mixed case');

// Strips extra # prefixes
ptmd_assert_same(normalize_hashtags('##double'), '#double', 'normalize_hashtags: strips double ##');

// Multi-word normalization
$result = normalize_hashtags('#abc #def #abc');
ptmd_assert_same($result, '#abc #def', 'normalize_hashtags: removes duplicate at end');

// ---------------------------------------------------------------------------
// add_required_platform_tags
// ---------------------------------------------------------------------------

// YouTube Shorts requires #Shorts
$caption = 'Great clip!';
$result = add_required_platform_tags($caption, 'YouTube Shorts');
ptmd_assert_true(str_contains($result, '#Shorts'), 'add_required_platform_tags: adds #Shorts to YouTube Shorts caption');

// Already has #Shorts — not added again
$caption = 'Great clip! #Shorts';
$result = add_required_platform_tags($caption, 'YouTube Shorts');
$count = substr_count($result, '#Shorts');
ptmd_assert_same($count, 1, 'add_required_platform_tags: does not duplicate #Shorts');

// TikTok has no required tags
$caption = 'TikTok caption.';
$result = add_required_platform_tags($caption, 'TikTok');
ptmd_assert_same($result, $caption, 'add_required_platform_tags: no change for platform with no required tags');

// Unknown platform returns caption unchanged
$result = add_required_platform_tags('test', 'LinkedIn');
ptmd_assert_same($result, 'test', 'add_required_platform_tags: returns caption unchanged for unknown platform');

// ---------------------------------------------------------------------------
// _ptmd_truncate_to_limit
// ---------------------------------------------------------------------------

// No truncation needed
ptmd_assert_same(_ptmd_truncate_to_limit('Hello world', 20), 'Hello world', '_ptmd_truncate_to_limit: no truncation when within limit');

// Exactly at limit
$text = str_repeat('a', 10);
ptmd_assert_same(_ptmd_truncate_to_limit($text, 10), $text, '_ptmd_truncate_to_limit: no truncation at exact limit');

// Truncation: appends ellipsis
$result = _ptmd_truncate_to_limit('Hello world foo bar', 10);
ptmd_assert_true(str_ends_with($result, '…'), '_ptmd_truncate_to_limit: appends ellipsis on truncation');
ptmd_assert_true(mb_strlen($result, 'UTF-8') <= 10, '_ptmd_truncate_to_limit: result respects limit');

// Word boundary: does not cut mid-word
$result = _ptmd_truncate_to_limit('Hello world', 8);
ptmd_assert_true(!str_contains($result, 'wor'), '_ptmd_truncate_to_limit: does not cut mid-word');

// ---------------------------------------------------------------------------
// format_caption_for_platform
// ---------------------------------------------------------------------------

// Basic: caption is returned within limit
$result = format_caption_for_platform('Great clip!', 'TikTok');
ptmd_assert_same($result, 'Great clip!', 'format_caption_for_platform: returns caption unchanged within limit');

// Hashtags are merged
$result = format_caption_for_platform('Great clip!', 'TikTok', '#tiktok #ptmd');
ptmd_assert_true(str_contains($result, '#tiktok'), 'format_caption_for_platform: appends hashtags');
ptmd_assert_true(str_contains($result, 'Great clip!'), 'format_caption_for_platform: preserves original caption');

// YouTube Shorts: #Shorts tag is injected
$result = format_caption_for_platform('Awesome clip', 'YouTube Shorts');
ptmd_assert_true(str_contains($result, '#Shorts'), 'format_caption_for_platform: injects #Shorts for YouTube Shorts');

// X: caption truncated to 280 chars
$longCaption = str_repeat('word ', 100); // 500 chars
$result = format_caption_for_platform($longCaption, 'X');
ptmd_assert_true(mb_strlen($result, 'UTF-8') <= 280, 'format_caption_for_platform: truncates X caption to 280');
ptmd_assert_true(str_ends_with($result, '…'), 'format_caption_for_platform: truncated X caption ends with ellipsis');

// Snapchat: caption truncated to 250 chars
$result = format_caption_for_platform(str_repeat('x ', 200), 'Snapchat Spotlight');
ptmd_assert_true(mb_strlen($result, 'UTF-8') <= 250, 'format_caption_for_platform: truncates Snapchat caption to 250');

// Duplicate hashtags are removed
$result = format_caption_for_platform('#ptmd news', 'TikTok', '#ptmd');
ptmd_assert_same(substr_count($result, '#ptmd'), 1, 'format_caption_for_platform: deduplicates hashtags');

// Unknown platform returns raw caption
$result = format_caption_for_platform('Some text', 'LinkedIn');
ptmd_assert_same($result, 'Some text', 'format_caption_for_platform: unknown platform returns raw caption');

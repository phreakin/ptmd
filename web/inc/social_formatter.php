<?php
/**
 * PTMD — Social Caption Formatter (inc/social_formatter.php)
 *
 * Transforms a raw caption (and optional hashtag string) into a platform-ready
 * final string.  All transforms are non-destructive: they truncate to the
 * platform's character limit only after appending required tags.
 *
 * Public API
 * ----------
 * format_caption_for_platform(string $caption, string $platform,
 *                             string $hashtags = '', ?string $title = null): string
 *
 * normalize_hashtags(string $text): string
 *
 * add_required_platform_tags(string $caption, string $platform): string
 */

require_once __DIR__ . '/social_platform_rules.php';

/**
 * Produce a final, platform-ready caption from the raw inputs.
 *
 * Steps:
 *  1. Merge $caption + $hashtags (trimmed).
 *  2. Add any required platform tags (e.g. #Shorts for YouTube Shorts).
 *  3. Truncate to the platform's max_caption_length, preserving whole words
 *     and appending "…" when truncation occurs.
 *
 * @param string      $caption   Raw caption text.
 * @param string      $platform  Target platform (must be in PTMD_PLATFORMS).
 * @param string      $hashtags  Additional hashtags to append (space-separated).
 * @param string|null $title     Optional title; used only for platforms that
 *                               have a dedicated title field (max_title_length).
 * @return string  Formatted caption ready to send to the platform.
 */
function format_caption_for_platform(
    string $caption,
    string $platform,
    string $hashtags = '',
    ?string $title = null
): string {
    $rules = get_platform_rules($platform);
    if (empty($rules)) {
        return $caption;
    }

    // Merge caption + hashtags (skip any tag already in the caption)
    $merged = trim($caption);
    $normalizedTags = normalize_hashtags(trim($hashtags));
    if ($normalizedTags !== '') {
        $tagTokens  = preg_split('/\s+/', $normalizedTags, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $captionLower = strtolower($merged);
        $tagsToAppend = [];
        foreach ($tagTokens as $tag) {
            if (!str_contains($captionLower, strtolower((string) $tag))) {
                $tagsToAppend[] = $tag;
            }
        }
        if (!empty($tagsToAppend)) {
            $merged = rtrim($merged) . ' ' . implode(' ', $tagsToAppend);
        }
    }

    // Add required platform tags
    $merged = add_required_platform_tags($merged, $platform);

    // Truncate to platform limit
    $limit = (int) $rules['max_caption_length'];
    if ($limit > 0) {
        $merged = _ptmd_truncate_to_limit($merged, $limit);
    }

    return $merged;
}

/**
 * Normalise a hashtag string:
 *  - Ensure each token starts with exactly one '#'.
 *  - Strip duplicate hashtags (case-insensitive).
 *  - Return a single space-separated string.
 *
 * @param string $text  Space-separated list of hashtags (with or without '#').
 * @return string
 */
function normalize_hashtags(string $text): string
{
    if (trim($text) === '') {
        return '';
    }

    $tokens = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($tokens)) {
        return '';
    }

    $seen       = [];
    $normalized = [];

    foreach ($tokens as $token) {
        $clean = ltrim((string) $token, '#');
        if ($clean === '') {
            continue;
        }
        $tag       = '#' . $clean;
        $lowerTag  = strtolower($tag);
        if (isset($seen[$lowerTag])) {
            continue;
        }
        $seen[$lowerTag] = true;
        $normalized[]    = $tag;
    }

    return implode(' ', $normalized);
}

/**
 * Append any tags required by the platform that are not already present in
 * the caption (e.g. "#Shorts" for YouTube Shorts).
 *
 * @param string $caption
 * @param string $platform
 * @return string
 */
function add_required_platform_tags(string $caption, string $platform): string
{
    $rules = get_platform_rules($platform);
    if (empty($rules['required_tags'])) {
        return $caption;
    }

    foreach ($rules['required_tags'] as $tag) {
        if (!str_contains($caption, $tag)) {
            $caption = rtrim($caption) . ' ' . $tag;
        }
    }

    return $caption;
}

/**
 * Truncate $text to at most $limit UTF-8 characters.
 * Attempts to break on a word boundary; appends "…" (1 char) when truncated.
 *
 * @param string $text
 * @param int    $limit
 * @return string
 */
function _ptmd_truncate_to_limit(string $text, int $limit): string
{
    if (mb_strlen($text, 'UTF-8') <= $limit) {
        return $text;
    }

    // Reserve one character for the ellipsis
    $cutoff    = $limit - 1;
    $truncated = mb_substr($text, 0, $cutoff, 'UTF-8');

    // Walk back to the last whitespace so we don't cut mid-word
    $lastSpace = mb_strrpos($truncated, ' ', 0, 'UTF-8');
    if ($lastSpace !== false && $lastSpace > (int) ($cutoff / 2)) {
        $truncated = mb_substr($truncated, 0, $lastSpace, 'UTF-8');
    }

    return rtrim($truncated) . '…';
}

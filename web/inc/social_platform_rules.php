<?php
/**
 * PTMD — Social Platform Rules (inc/social_platform_rules.php)
 *
 * Defines the canonical set of supported platforms and per-platform content
 * policy constraints.  Also exposes preflight_check_queue_item() which must
 * be called before inserting a row into social_post_queue.
 *
 * Rule fields
 * -----------
 * max_duration_sec    int|null   Maximum clip duration in seconds (null = no hard limit)
 * max_file_size_mb    int|null   Maximum video file size in megabytes (null = no hard limit)
 * aspect_ratio        string     Expected aspect ratio (e.g. '9:16', 'any')
 * max_caption_length  int        Maximum caption / description character count
 * max_title_length    int|null   Maximum title length (null = platform has no separate title field)
 * max_hashtags        int|null   Maximum number of hashtags (null = no documented limit)
 * required_tags       string[]   Tags that must appear in the final caption
 * allowed_content_types string[] Known content-type labels accepted for this platform
 * supports_scheduling bool       Whether platform API supports scheduled publishing
 * phase               int        Rollout phase (1 = already dispatched; 2 = upcoming)
 * notes               string     Human-readable policy notes
 */

/** Canonical ordered list of all supported platforms. */
const PTMD_PLATFORMS = [
    'YouTube',
    'YouTube Shorts',
    'TikTok',
    'Instagram Reels',
    'Facebook Reels',
    'Snapchat Spotlight',
    'X',
    'Pinterest Idea Pins',
];

/**
 * Return the content-policy rules for the given platform.
 *
 * @return array<string,mixed>  Rule map; empty array for unknown platforms.
 */
function get_platform_rules(string $platform): array
{
    $rules = [
        'YouTube' => [
            'max_duration_sec'     => null,
            'max_file_size_mb'     => 262144, // 256 GB
            'aspect_ratio'         => 'any',
            'max_caption_length'   => 5000,
            'max_title_length'     => 100,
            'max_hashtags'         => null,
            'required_tags'        => [],
            'allowed_content_types'=> ['full documentary', 'episode', 'video'],
            'supports_scheduling'  => true,
            'phase'                => 1,
            'notes'                => 'YouTube Data API v3. Scopes: youtube.upload.',
        ],
        'YouTube Shorts' => [
            'max_duration_sec'     => 60,
            'max_file_size_mb'     => 262144,
            'aspect_ratio'         => '9:16',
            'max_caption_length'   => 1000,
            'max_title_length'     => 100,
            'max_hashtags'         => null,
            'required_tags'        => ['#Shorts'],
            'allowed_content_types'=> ['teaser', 'clip', 'follow-up'],
            'supports_scheduling'  => true,
            'phase'                => 1,
            'notes'                => 'Standard YouTube upload with #Shorts tag and vertical aspect ratio.',
        ],
        'TikTok' => [
            'max_duration_sec'     => 600,
            'max_file_size_mb'     => 4096, // 4 GB
            'aspect_ratio'         => '9:16',
            'max_caption_length'   => 2200,
            'max_title_length'     => null,
            'max_hashtags'         => 100,
            'required_tags'        => [],
            'allowed_content_types'=> ['teaser', 'clip'],
            'supports_scheduling'  => false,
            'phase'                => 1,
            'notes'                => 'TikTok Content Posting API. Requires TIKTOK_CLIENT_KEY and TIKTOK_CLIENT_SECRET.',
        ],
        'Instagram Reels' => [
            'max_duration_sec'     => 90,
            'max_file_size_mb'     => 1024,
            'aspect_ratio'         => '9:16',
            'max_caption_length'   => 2200,
            'max_title_length'     => null,
            'max_hashtags'         => 30,
            'required_tags'        => [],
            'allowed_content_types'=> ['teaser', 'clip', 'reel'],
            'supports_scheduling'  => true,
            'phase'                => 1,
            'notes'                => 'Meta Graph API. Three-step publish flow: create container → poll → publish.',
        ],
        'Facebook Reels' => [
            'max_duration_sec'     => 90,
            'max_file_size_mb'     => 1024,
            'aspect_ratio'         => '9:16',
            'max_caption_length'   => 2200,
            'max_title_length'     => null,
            'max_hashtags'         => null,
            'required_tags'        => [],
            'allowed_content_types'=> ['teaser', 'clip', 'reel'],
            'supports_scheduling'  => true,
            'phase'                => 1,
            'notes'                => 'Meta Graph API. Three-phase upload: start → binary upload → finish.',
        ],
        'Snapchat Spotlight' => [
            'max_duration_sec'     => 60,
            'max_file_size_mb'     => 32,
            'aspect_ratio'         => '9:16',
            'max_caption_length'   => 250,
            'max_title_length'     => null,
            'max_hashtags'         => 5,
            'required_tags'        => [],
            'allowed_content_types'=> ['teaser', 'clip'],
            'supports_scheduling'  => false,
            'phase'                => 2,
            'notes'                => 'Snapchat Marketing API. Strict 32 MB limit; captions truncated to 250 chars.',
        ],
        'X' => [
            'max_duration_sec'     => 140,
            'max_file_size_mb'     => 512,
            'aspect_ratio'         => 'any',
            'max_caption_length'   => 280,
            'max_title_length'     => null,
            'max_hashtags'         => null,
            'required_tags'        => [],
            'allowed_content_types'=> ['launch post', 'follow-up clip', 'teaser', 'clip'],
            'supports_scheduling'  => true,
            'phase'                => 1,
            'notes'                => 'X API v2. Chunked media upload then tweet creation.',
        ],
        'Pinterest Idea Pins' => [
            'max_duration_sec'     => 60,
            'max_file_size_mb'     => 100,
            'aspect_ratio'         => '9:16',
            'max_caption_length'   => 500,
            'max_title_length'     => 100,
            'max_hashtags'         => null,
            'required_tags'        => [],
            'allowed_content_types'=> ['teaser', 'clip', 'idea'],
            'supports_scheduling'  => false,
            'phase'                => 2,
            'notes'                => 'Pinterest API v5. Idea Pins support multi-page video content.',
        ],
    ];

    return $rules[$platform] ?? [];
}

/**
 * Validate a social_post_queue item (associative array) against its platform
 * rules before it is inserted or dispatched.
 *
 * Expected keys: platform, caption, content_type, duration_sec (optional),
 *                file_size_mb (optional), aspect_ratio (optional)
 *
 * @param  array<string,mixed> $item
 * @return array{ok:bool, errors:string[], warnings:string[]}
 */
function preflight_check_queue_item(array $item): array
{
    $errors   = [];
    $warnings = [];

    $platform = (string) ($item['platform'] ?? '');
    if ($platform === '') {
        return ['ok' => false, 'errors' => ['Platform is required.'], 'warnings' => []];
    }

    $rules = get_platform_rules($platform);
    if (empty($rules)) {
        return ['ok' => false, 'errors' => ["Unknown platform: {$platform}"], 'warnings' => []];
    }

    // Caption length
    $caption = (string) ($item['caption'] ?? '');
    $captionLen = mb_strlen($caption, 'UTF-8');
    if ($captionLen > $rules['max_caption_length']) {
        $errors[] = "Caption exceeds {$rules['max_caption_length']} character limit for {$platform} ({$captionLen} chars).";
    }

    // Title length (when a separate title field is present)
    if ($rules['max_title_length'] !== null) {
        $title = (string) ($item['title'] ?? '');
        $titleLen = mb_strlen($title, 'UTF-8');
        if ($title !== '' && $titleLen > $rules['max_title_length']) {
            $errors[] = "Title exceeds {$rules['max_title_length']} character limit for {$platform} ({$titleLen} chars).";
        }
    }

    // Hashtag count
    if ($rules['max_hashtags'] !== null) {
        preg_match_all('/#\w+/u', $caption, $tagMatches);
        $tagCount = count($tagMatches[0] ?? []);
        if ($tagCount > $rules['max_hashtags']) {
            $errors[] = "Caption contains {$tagCount} hashtags; {$platform} allows a maximum of {$rules['max_hashtags']}.";
        }
    }

    // Required tags
    foreach ($rules['required_tags'] as $requiredTag) {
        if (!str_contains($caption, $requiredTag)) {
            $warnings[] = "Caption is missing required tag \"{$requiredTag}\" for {$platform}.";
        }
    }

    // Duration (if provided)
    $durationSec = isset($item['duration_sec']) ? (float) $item['duration_sec'] : null;
    if ($durationSec !== null && $rules['max_duration_sec'] !== null && $durationSec > $rules['max_duration_sec']) {
        $errors[] = "Clip duration {$durationSec}s exceeds {$platform} maximum of {$rules['max_duration_sec']}s.";
    }

    // File size (if provided)
    $fileSizeMb = isset($item['file_size_mb']) ? (float) $item['file_size_mb'] : null;
    if ($fileSizeMb !== null && $rules['max_file_size_mb'] !== null && $fileSizeMb > $rules['max_file_size_mb']) {
        $errors[] = "File size {$fileSizeMb} MB exceeds {$platform} maximum of {$rules['max_file_size_mb']} MB.";
    }

    // Aspect ratio (if provided)
    $aspectRatio = (string) ($item['aspect_ratio'] ?? '');
    if ($aspectRatio !== '' && $rules['aspect_ratio'] !== 'any' && $aspectRatio !== $rules['aspect_ratio']) {
        $warnings[] = "Aspect ratio {$aspectRatio} differs from the expected {$rules['aspect_ratio']} for {$platform}.";
    }

    // Content type (warn if not in allowed list)
    $contentType = (string) ($item['content_type'] ?? '');
    if ($contentType !== '' && !empty($rules['allowed_content_types']) && !in_array($contentType, $rules['allowed_content_types'], true)) {
        $warnings[] = "Content type \"{$contentType}\" is not in the known list for {$platform}: " . implode(', ', $rules['allowed_content_types']) . '.';
    }

    return [
        'ok'       => empty($errors),
        'errors'   => $errors,
        'warnings' => $warnings,
    ];
}

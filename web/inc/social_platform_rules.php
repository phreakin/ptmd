<?php
/**
 * PTMD — Social Platform Rules  (inc/social_platform_rules.php)
 *
 * Centralises platform metadata used across the publishing pipeline:
 *   - Canonical platform names (PTMD_PLATFORMS constant)
 *   - OAuth credential fields required for onboarding
 *   - API scopes needed for posting
 *   - Human-readable policy checklist for each platform
 *   - Technical constraints (max video duration, accepted formats, aspect ratios)
 *
 * All functions are pure (no DB I/O) so they can be used in tests without mocks.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Platform registry
// ---------------------------------------------------------------------------

/**
 * Canonical platform name list used across the entire PTMD publishing pipeline.
 * Keys are the DB-stored platform string values.
 * Values are display labels (currently the same as keys).
 */
const PTMD_PLATFORMS = [
    'YouTube'             => 'YouTube',
    'YouTube Shorts'      => 'YouTube Shorts',
    'TikTok'              => 'TikTok',
    'Instagram Reels'     => 'Instagram Reels',
    'Facebook Reels'      => 'Facebook Reels',
    'Snapchat Spotlight'  => 'Snapchat Spotlight',
    'X'                   => 'X',
    'Pinterest Idea Pins' => 'Pinterest Idea Pins',
];

// ---------------------------------------------------------------------------
// Credential fields
// ---------------------------------------------------------------------------

/**
 * Return the credential fields that must be supplied when onboarding a platform
 * account. Each entry is:
 *   ['key' => string, 'label' => string, 'type' => 'text'|'secret'|'json']
 *
 * These fields are stored (encrypted in production) in social_accounts.auth_config_json.
 */
function ptmd_platform_credential_fields(string $platform): array
{
    return match ($platform) {
        'YouTube', 'YouTube Shorts' => [
            ['key' => 'client_id',     'label' => 'OAuth Client ID',     'type' => 'secret'],
            ['key' => 'client_secret', 'label' => 'OAuth Client Secret', 'type' => 'secret'],
            ['key' => 'refresh_token', 'label' => 'Refresh Token',       'type' => 'secret'],
        ],

        'TikTok' => [
            ['key' => 'client_key',    'label' => 'Client Key',          'type' => 'secret'],
            ['key' => 'client_secret', 'label' => 'Client Secret',       'type' => 'secret'],
            ['key' => 'access_token',  'label' => 'User Access Token',   'type' => 'secret'],
            ['key' => 'open_id',       'label' => 'TikTok Open ID',      'type' => 'text'],
        ],

        'Instagram Reels', 'Facebook Reels' => [
            ['key' => 'app_id',         'label' => 'Meta App ID',       'type' => 'text'],
            ['key' => 'app_secret',     'label' => 'Meta App Secret',   'type' => 'secret'],
            ['key' => 'access_token',   'label' => 'Page/User Token',   'type' => 'secret'],
            ['key' => 'instagram_user_id',
                                        'label' => 'Instagram User ID', 'type' => 'text'],
        ],

        'Snapchat Spotlight' => [
            ['key' => 'client_id',      'label' => 'Snap Client ID',    'type' => 'secret'],
            ['key' => 'client_secret',  'label' => 'Snap Client Secret','type' => 'secret'],
            ['key' => 'refresh_token',  'label' => 'Refresh Token',     'type' => 'secret'],
            ['key' => 'ad_account_id',  'label' => 'Ad Account ID',     'type' => 'text'],
        ],

        'X' => [
            ['key' => 'api_key',            'label' => 'API Key',           'type' => 'secret'],
            ['key' => 'api_key_secret',     'label' => 'API Key Secret',    'type' => 'secret'],
            ['key' => 'access_token',       'label' => 'Access Token',      'type' => 'secret'],
            ['key' => 'access_token_secret','label' => 'Access Token Secret','type' => 'secret'],
        ],

        'Pinterest Idea Pins' => [
            ['key' => 'app_id',         'label' => 'Pinterest App ID',  'type' => 'text'],
            ['key' => 'app_secret',     'label' => 'App Secret',        'type' => 'secret'],
            ['key' => 'access_token',   'label' => 'Access Token',      'type' => 'secret'],
            ['key' => 'board_id',       'label' => 'Board ID',          'type' => 'text'],
        ],

        default => [],
    };
}

// ---------------------------------------------------------------------------
// Required OAuth scopes
// ---------------------------------------------------------------------------

/**
 * Return an array of OAuth scope strings that must be granted for posting to
 * the given platform.
 */
function ptmd_platform_required_scopes(string $platform): array
{
    return match ($platform) {
        'YouTube', 'YouTube Shorts' => [
            'https://www.googleapis.com/auth/youtube.upload',
            'https://www.googleapis.com/auth/youtube.readonly',
        ],

        'TikTok' => [
            'video.upload',
            'video.list',
        ],

        'Instagram Reels', 'Facebook Reels' => [
            'pages_show_list',
            'pages_read_engagement',
            'instagram_basic',
            'instagram_content_publish',
        ],

        'Snapchat Spotlight' => [
            'snapchat-marketing-api',
            'snapchat-marketing-api:snap-ads',
        ],

        'X' => [
            'tweet.read',
            'tweet.write',
            'users.read',
            'media.write',
        ],

        'Pinterest Idea Pins' => [
            'boards:read',
            'boards:write',
            'pins:read',
            'pins:write',
        ],

        default => [],
    };
}

// ---------------------------------------------------------------------------
// Policy checklist
// ---------------------------------------------------------------------------

/**
 * Return a human-readable list of platform policy requirements that the
 * operator must confirm before enabling automated posting.
 * Each entry: ['label' => string, 'url' => string|null]
 */
function ptmd_platform_policy_checklist(string $platform): array
{
    return match ($platform) {
        'YouTube', 'YouTube Shorts' => [
            ['label' => 'Comply with YouTube Community Guidelines',          'url' => 'https://www.youtube.com/t/community_guidelines'],
            ['label' => 'Adhere to YouTube Copyright policies',              'url' => 'https://www.youtube.com/t/copyright_overview'],
            ['label' => 'Video is original content or licensed',             'url' => null],
            ['label' => 'Monetisation settings match channel eligibility',   'url' => null],
        ],

        'TikTok' => [
            ['label' => 'Comply with TikTok Community Guidelines',          'url' => 'https://www.tiktok.com/community-guidelines'],
            ['label' => 'Content meets TikTok music licensing requirements', 'url' => null],
            ['label' => 'Video is vertical (9:16) at 1080p minimum',        'url' => null],
        ],

        'Instagram Reels', 'Facebook Reels' => [
            ['label' => 'Comply with Meta Community Standards',             'url' => 'https://transparency.fb.com/policies/community-standards/'],
            ['label' => 'Content meets Instagram Professional Account reqs', 'url' => null],
            ['label' => 'Video is vertical (9:16) at 1080×1920 minimum',    'url' => null],
        ],

        'Snapchat Spotlight' => [
            ['label' => 'Comply with Snap Community Guidelines',            'url' => 'https://values.snap.com/privacy/transparency/community-guidelines'],
            ['label' => 'Video is 9:16 vertical, max 60 seconds',           'url' => null],
        ],

        'X' => [
            ['label' => 'Comply with X Rules and policies',                 'url' => 'https://help.twitter.com/en/rules-and-policies/twitter-rules'],
            ['label' => 'Media upload meets file size limits (512 MB video)','url' => null],
        ],

        'Pinterest Idea Pins' => [
            ['label' => 'Comply with Pinterest Community Guidelines',       'url' => 'https://policy.pinterest.com/en/community-guidelines'],
            ['label' => 'Board exists and is public',                       'url' => null],
            ['label' => 'Content meets Pinterest Idea Pin format requirements','url' => null],
        ],

        default => [],
    };
}

// ---------------------------------------------------------------------------
// Technical constraints
// ---------------------------------------------------------------------------

/**
 * Return technical posting constraints for a given platform:
 *   max_duration_s    int|null  — maximum video length in seconds
 *   min_duration_s    int|null  — minimum video length in seconds
 *   aspect_ratio      string|null — target aspect ratio ('9:16', '16:9', etc.)
 *   max_file_size_mb  int|null  — maximum video file size in megabytes
 *   accepted_formats  string[]  — accepted file extensions (lowercase)
 *   max_caption_chars int|null  — character limit for captions/descriptions
 */
function ptmd_platform_constraints(string $platform): array
{
    return match ($platform) {
        'YouTube' => [
            'max_duration_s'    => null,          // no hard limit for standard uploads
            'min_duration_s'    => null,
            'aspect_ratio'      => '16:9',
            'max_file_size_mb'  => 256000,        // 256 GB
            'accepted_formats'  => ['mp4', 'mov', 'avi', 'wmv', 'flv', 'mkv'],
            'max_caption_chars' => 5000,
        ],

        'YouTube Shorts' => [
            'max_duration_s'    => 60,
            'min_duration_s'    => 1,
            'aspect_ratio'      => '9:16',
            'max_file_size_mb'  => 256000,
            'accepted_formats'  => ['mp4', 'mov'],
            'max_caption_chars' => 5000,
        ],

        'TikTok' => [
            'max_duration_s'    => 600,
            'min_duration_s'    => 3,
            'aspect_ratio'      => '9:16',
            'max_file_size_mb'  => 4000,          // 4 GB
            'accepted_formats'  => ['mp4', 'mov', 'webm'],
            'max_caption_chars' => 2200,
        ],

        'Instagram Reels' => [
            'max_duration_s'    => 90,
            'min_duration_s'    => 3,
            'aspect_ratio'      => '9:16',
            'max_file_size_mb'  => 1000,
            'accepted_formats'  => ['mp4', 'mov'],
            'max_caption_chars' => 2200,
        ],

        'Facebook Reels' => [
            'max_duration_s'    => 90,
            'min_duration_s'    => 3,
            'aspect_ratio'      => '9:16',
            'max_file_size_mb'  => 1000,
            'accepted_formats'  => ['mp4', 'mov'],
            'max_caption_chars' => 5000,
        ],

        'Snapchat Spotlight' => [
            'max_duration_s'    => 60,
            'min_duration_s'    => 3,
            'aspect_ratio'      => '9:16',
            'max_file_size_mb'  => 500,
            'accepted_formats'  => ['mp4'],
            'max_caption_chars' => 500,
        ],

        'X' => [
            'max_duration_s'    => 140,
            'min_duration_s'    => null,
            'aspect_ratio'      => null,          // landscape, portrait, square all supported
            'max_file_size_mb'  => 512,
            'accepted_formats'  => ['mp4', 'mov'],
            'max_caption_chars' => 280,
        ],

        'Pinterest Idea Pins' => [
            'max_duration_s'    => 60,
            'min_duration_s'    => 3,
            'aspect_ratio'      => '9:16',
            'max_file_size_mb'  => 2000,
            'accepted_formats'  => ['mp4', 'mov'],
            'max_caption_chars' => 500,
        ],

        default => [
            'max_duration_s'    => null,
            'min_duration_s'    => null,
            'aspect_ratio'      => null,
            'max_file_size_mb'  => null,
            'accepted_formats'  => [],
            'max_caption_chars' => null,
        ],
    };
}

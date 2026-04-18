<?php
/**
 * PTMD / Social Ledger platform capabilities registry
 *
 * Purpose:
 * - Centralize what each posting platform supports
 * - Drive validation, UI behavior, review gates, and future automation
 * - Keep posting logic deterministic and easy to extend
 */

declare(strict_types=1);

if (!function_exists('platform_capabilities')) {
    function platform_capabilities(): array
    {
        return [
            'youtube' => [
                'platform_key'            => 'youtube',
                'display_name'            => 'YouTube',
                'enabled_by_default'      => true,
                'sort_order'              => 10,
                'default_content_type'    => 'full documentary',
                'default_caption_prefix'  => 'New case from Paper Trail MD.',
                'default_hashtags'        => '#investigation #documentary',
                'default_status'          => 'queued',

                'supports_video'          => true,
                'supports_image'          => true,
                'supports_links'          => true,
                'supports_threads'        => false,
                'supports_first_comment'  => true,
                'supports_title'          => true,
                'supports_thumbnail'      => true,
                'supports_hashtags'       => true,

                'review_required'         => true,
                'autopost_allowed'        => false,
                'preferred_orientation'   => 'landscape',
                'max_caption_length'      => 5000,

                'allowed_content_types'   => [
                    'full documentary',
                    'follow-up',
                    'promo',
                    'community/update post',
                ],
            ],

            'youtube_shorts' => [
                'platform_key'            => 'youtube_shorts',
                'display_name'            => 'YouTube Shorts',
                'enabled_by_default'      => true,
                'sort_order'              => 20,
                'default_content_type'    => 'teaser',
                'default_caption_prefix'  => 'Short cut from Paper Trail MD.',
                'default_hashtags'        => '#shorts #investigation',
                'default_status'          => 'queued',

                'supports_video'          => true,
                'supports_image'          => false,
                'supports_links'          => false,
                'supports_threads'        => false,
                'supports_first_comment'  => true,
                'supports_title'          => true,
                'supports_thumbnail'      => true,
                'supports_hashtags'       => true,

                'review_required'         => true,
                'autopost_allowed'        => false,
                'preferred_orientation'   => 'vertical',
                'max_caption_length'      => 1000,

                'allowed_content_types'   => [
                    'teaser',
                    'clip',
                    'follow-up',
                    'reaction',
                    'promo',
                ],
            ],

            'tiktok' => [
                'platform_key'            => 'tiktok',
                'display_name'            => 'TikTok',
                'enabled_by_default'      => true,
                'sort_order'              => 30,
                'default_content_type'    => 'teaser',
                'default_caption_prefix'  => 'Fresh PTMD clip just dropped.',
                'default_hashtags'        => '#tiktok #investigation',
                'default_status'          => 'queued',

                'supports_video'          => true,
                'supports_image'          => true,
                'supports_links'          => false,
                'supports_threads'        => false,
                'supports_first_comment'  => false,
                'supports_title'          => false,
                'supports_thumbnail'      => false,
                'supports_hashtags'       => true,

                'review_required'         => true,
                'autopost_allowed'        => false,
                'preferred_orientation'   => 'vertical',
                'max_caption_length'      => 2200,

                'allowed_content_types'   => [
                    'teaser',
                    'clip',
                    'reaction',
                    'promo',
                    'follow-up',
                ],
            ],

            'instagram_reels' => [
                'platform_key'            => 'instagram_reels',
                'display_name'            => 'Instagram Reels',
                'enabled_by_default'      => true,
                'sort_order'              => 40,
                'default_content_type'    => 'teaser',
                'default_caption_prefix'  => 'New reel from Paper Trail MD.',
                'default_hashtags'        => '#reels #investigation',
                'default_status'          => 'queued',

                'supports_video'          => true,
                'supports_image'          => false,
                'supports_links'          => false,
                'supports_threads'        => false,
                'supports_first_comment'  => true,
                'supports_title'          => false,
                'supports_thumbnail'      => true,
                'supports_hashtags'       => true,

                'review_required'         => true,
                'autopost_allowed'        => false,
                'preferred_orientation'   => 'vertical',
                'max_caption_length'      => 2200,

                'allowed_content_types'   => [
                    'teaser',
                    'clip',
                    'reaction',
                    'promo',
                    'follow-up',
                ],
            ],

            'instagram_feed' => [
                'platform_key'            => 'instagram_feed',
                'display_name'            => 'Instagram Feed',
                'enabled_by_default'      => true,
                'sort_order'              => 45,
                'default_content_type'    => 'promo',
                'default_caption_prefix'  => 'New from Paper Trail MD.',
                'default_hashtags'        => '#investigation #casefile',
                'default_status'          => 'draft',

                'supports_video'          => true,
                'supports_image'          => true,
                'supports_links'          => false,
                'supports_threads'        => false,
                'supports_first_comment'  => true,
                'supports_title'          => false,
                'supports_thumbnail'      => true,
                'supports_hashtags'       => true,

                'review_required'         => true,
                'autopost_allowed'        => false,
                'preferred_orientation'   => 'square',
                'max_caption_length'      => 2200,

                'allowed_content_types'   => [
                    'promo',
                    'clip',
                    'community/update post',
                    'reaction',
                ],
            ],

            'facebook_reels' => [
                'platform_key'            => 'facebook_reels',
                'display_name'            => 'Facebook Reels',
                'enabled_by_default'      => true,
                'sort_order'              => 50,
                'default_content_type'    => 'clip',
                'default_caption_prefix'  => 'Watch this Paper Trail MD clip.',
                'default_hashtags'        => '#reels #news',
                'default_status'          => 'queued',

                'supports_video'          => true,
                'supports_image'          => false,
                'supports_links'          => true,
                'supports_threads'        => false,
                'supports_first_comment'  => false,
                'supports_title'          => false,
                'supports_thumbnail'      => true,
                'supports_hashtags'       => true,

                'review_required'         => true,
                'autopost_allowed'        => false,
                'preferred_orientation'   => 'vertical',
                'max_caption_length'      => 5000,

                'allowed_content_types'   => [
                    'clip',
                    'teaser',
                    'follow-up',
                    'promo',
                ],
            ],

            'facebook_feed' => [
                'platform_key'            => 'facebook_feed',
                'display_name'            => 'Facebook Feed',
                'enabled_by_default'      => true,
                'sort_order'              => 55,
                'default_content_type'    => 'launch post',
                'default_caption_prefix'  => 'New case from Paper Trail MD.',
                'default_hashtags'        => '#investigation #documentary',
                'default_status'          => 'draft',

                'supports_video'          => true,
                'supports_image'          => true,
                'supports_links'          => true,
                'supports_threads'        => false,
                'supports_first_comment'  => false,
                'supports_title'          => true,
                'supports_thumbnail'      => true,
                'supports_hashtags'       => true,

                'review_required'         => true,
                'autopost_allowed'        => false,
                'preferred_orientation'   => 'landscape',
                'max_caption_length'      => 63206,

                'allowed_content_types'   => [
                    'launch post',
                    'promo',
                    'community/update post',
                    'follow-up',
                    'clip',
                ],
            ],

            'x' => [
                'platform_key'            => 'x',
                'display_name'            => 'X',
                'enabled_by_default'      => true,
                'sort_order'              => 60,
                'default_content_type'    => 'launch post',
                'default_caption_prefix'  => 'New post from Paper Trail MD.',
                'default_hashtags'        => '#news #journalism',
                'default_status'          => 'queued',

                'supports_video'          => true,
                'supports_image'          => true,
                'supports_links'          => true,
                'supports_threads'        => true,
                'supports_first_comment'  => false,
                'supports_title'          => false,
                'supports_thumbnail'      => true,
                'supports_hashtags'       => true,

                'review_required'         => true,
                'autopost_allowed'        => false,
                'preferred_orientation'   => 'landscape',
                'max_caption_length'      => 280,

                'allowed_content_types'   => [
                    'launch post',
                    'thread',
                    'follow-up',
                    'reaction',
                    'promo',
                    'clip',
                ],
            ],

            'threads' => [
                'platform_key'            => 'threads',
                'display_name'            => 'Threads',
                'enabled_by_default'      => true,
                'sort_order'              => 70,
                'default_content_type'    => 'reaction',
                'default_caption_prefix'  => 'New thought from Paper Trail MD.',
                'default_hashtags'        => '#threads #investigation',
                'default_status'          => 'draft',

                'supports_video'          => true,
                'supports_image'          => true,
                'supports_links'          => false,
                'supports_threads'        => true,
                'supports_first_comment'  => false,
                'supports_title'          => false,
                'supports_thumbnail'      => false,
                'supports_hashtags'       => true,

                'review_required'         => true,
                'autopost_allowed'        => false,
                'preferred_orientation'   => 'square',
                'max_caption_length'      => 500,

                'allowed_content_types'   => [
                    'reaction',
                    'thread',
                    'promo',
                    'community/update post',
                    'clip',
                ],
            ],

            'linkedin' => [
                'platform_key'            => 'linkedin',
                'display_name'            => 'LinkedIn',
                'enabled_by_default'      => false,
                'sort_order'              => 80,
                'default_content_type'    => 'community/update post',
                'default_caption_prefix'  => 'A new insight from Paper Trail MD.',
                'default_hashtags'        => '#analysis #media #research',
                'default_status'          => 'draft',

                'supports_video'          => true,
                'supports_image'          => true,
                'supports_links'          => true,
                'supports_threads'        => false,
                'supports_first_comment'  => false,
                'supports_title'          => true,
                'supports_thumbnail'      => true,
                'supports_hashtags'       => true,

                'review_required'         => true,
                'autopost_allowed'        => false,
                'preferred_orientation'   => 'landscape',
                'max_caption_length'      => 3000,

                'allowed_content_types'   => [
                    'community/update post',
                    'promo',
                    'launch post',
                    'follow-up',
                ],
            ],

            'reddit' => [
                'platform_key'            => 'reddit',
                'display_name'            => 'Reddit',
                'enabled_by_default'      => false,
                'sort_order'              => 90,
                'default_content_type'    => 'thread',
                'default_caption_prefix'  => 'Discussion thread:',
                'default_hashtags'        => '',
                'default_status'          => 'draft',

                'supports_video'          => true,
                'supports_image'          => true,
                'supports_links'          => true,
                'supports_threads'        => true,
                'supports_first_comment'  => false,
                'supports_title'          => true,
                'supports_thumbnail'      => true,
                'supports_hashtags'       => false,

                'review_required'         => true,
                'autopost_allowed'        => false,
                'preferred_orientation'   => 'landscape',
                'max_caption_length'      => 40000,

                'allowed_content_types'   => [
                    'thread',
                    'launch post',
                    'reaction',
                    'community/update post',
                ],
            ],

            'pinterest' => [
                'platform_key'            => 'pinterest',
                'display_name'            => 'Pinterest',
                'enabled_by_default'      => false,
                'sort_order'              => 100,
                'default_content_type'    => 'promo',
                'default_caption_prefix'  => 'Save this case from Paper Trail MD.',
                'default_hashtags'        => '#investigation #research',
                'default_status'          => 'draft',

                'supports_video'          => true,
                'supports_image'          => true,
                'supports_links'          => true,
                'supports_threads'        => false,
                'supports_first_comment'  => false,
                'supports_title'          => true,
                'supports_thumbnail'      => true,
                'supports_hashtags'       => true,

                'review_required'         => true,
                'autopost_allowed'        => false,
                'preferred_orientation'   => 'vertical',
                'max_caption_length'      => 500,

                'allowed_content_types'   => [
                    'promo',
                    'clip',
                    'launch post',
                ],
            ],

            'snapchat_spotlight' => [
                'platform_key'            => 'snapchat_spotlight',
                'display_name'            => 'Snapchat Spotlight',
                'enabled_by_default'      => false,
                'sort_order'              => 110,
                'default_content_type'    => 'clip',
                'default_caption_prefix'  => 'New Spotlight clip from Paper Trail MD.',
                'default_hashtags'        => '',
                'default_status'          => 'draft',

                'supports_video'          => true,
                'supports_image'          => false,
                'supports_links'          => false,
                'supports_threads'        => false,
                'supports_first_comment'  => false,
                'supports_title'          => false,
                'supports_thumbnail'      => false,
                'supports_hashtags'       => false,

                'review_required'         => true,
                'autopost_allowed'        => false,
                'preferred_orientation'   => 'vertical',
                'max_caption_length'      => 250,

                'allowed_content_types'   => [
                    'clip',
                    'teaser',
                    'reaction',
                    'promo',
                ],
            ],
        ];
    }
}

if (!function_exists('platform_capability')) {
    function platform_capability(string $platformKey): ?array
    {
        $all = platform_capabilities();
        return $all[$platformKey] ?? null;
    }
}

if (!function_exists('platform_exists')) {
    function platform_exists(string $platformKey): bool
    {
        return platform_capability($platformKey) !== null;
    }
}

if (!function_exists('platform_supports')) {
    function platform_supports(string $platformKey, string $capability): bool
    {
        $platform = platform_capability($platformKey);
        if (!$platform) {
            return false;
        }

        return (bool) ($platform[$capability] ?? false);
    }
}

if (!function_exists('platform_allowed_content_types')) {
    function platform_allowed_content_types(string $platformKey): array
    {
        $platform = platform_capability($platformKey);
        return $platform['allowed_content_types'] ?? [];
    }
}

if (!function_exists('enabled_platform_capabilities')) {
    function enabled_platform_capabilities(bool $onlyDefaultEnabled = true): array
    {
        $platforms = platform_capabilities();

        if ($onlyDefaultEnabled) {
            $platforms = array_filter(
                $platforms,
                static fn(array $platform): bool => (bool) ($platform['enabled_by_default'] ?? false)
            );
        }

        uasort(
            $platforms,
            static fn(array $a, array $b): int => ($a['sort_order'] ?? 9999) <=> ($b['sort_order'] ?? 9999)
        );

        return $platforms;
    }
}

if (!function_exists('platform_max_caption_length')) {
    function platform_max_caption_length(string $platformKey): ?int
    {
        $platform = platform_capability($platformKey);
        if (!$platform) {
            return null;
        }

        return isset($platform['max_caption_length']) ? (int) $platform['max_caption_length'] : null;
    }
}

if (!function_exists('platform_review_required')) {
    function platform_review_required(string $platformKey): bool
    {
        $platform = platform_capability($platformKey);
        return (bool) ($platform['review_required'] ?? true);
    }
}

if (!function_exists('platform_autopost_allowed')) {
    function platform_autopost_allowed(string $platformKey): bool
    {
        $platform = platform_capability($platformKey);
        return (bool) ($platform['autopost_allowed'] ?? false);
    }
}
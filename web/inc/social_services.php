<?php
/**
 * PTMD — Social Platform Service Stubs (inc/social_services.php)
 *
 * Modular stubs for posting content to each supported platform.
 * Each function:
 *  - Accepts a queue item array
 *  - Logs the attempt to social_post_logs
 *  - Updates queue item status
 *  - Returns ['ok' => bool, 'external_post_id' => string|null, 'error' => string|null]
 *
 * TODO: Replace each stub body with the real platform SDK/API call.
 *       Auth credentials for each platform are stored in social_accounts.auth_config_json.
 */

// ---------------------------------------------------------------------------
// Dispatcher — routes a queue item to the correct platform function
// ---------------------------------------------------------------------------

function dispatch_social_post(array $queueItem): array
{
    $platform = strtolower(str_replace(' ', '_', $queueItem['platform'] ?? ''));

    $result = match ($platform) {
        'youtube'          => post_to_youtube($queueItem),
        'youtube_shorts'   => post_to_youtube_shorts($queueItem),
        'tiktok'           => post_to_tiktok($queueItem),
        'instagram_reels'  => post_to_instagram_reels($queueItem),
        'facebook_reels'   => post_to_facebook_reels($queueItem),
        'x'                => post_to_x($queueItem),
        default            => ['ok' => false, 'error' => 'Unknown platform: ' . $queueItem['platform']],
    };

    // Write log entry
    $pdo = get_db();
    if ($pdo) {
        $stmt = $pdo->prepare(
            'INSERT INTO social_post_logs
             (queue_id, platform, request_payload_json, response_payload_json, status, created_at)
             VALUES (:queue_id, :platform, :req, :res, :status, NOW())'
        );
        $stmt->execute([
            'queue_id' => (int) $queueItem['id'],
            'platform' => $queueItem['platform'],
            'req'      => json_encode(['queue_item' => $queueItem], JSON_UNESCAPED_UNICODE),
            'res'      => json_encode($result,                       JSON_UNESCAPED_UNICODE),
            'status'   => $result['ok'] ? 'posted' : 'failed',
        ]);

        // Update queue item status
        $newStatus = $result['ok'] ? 'posted' : 'failed';
        $stmt2 = $pdo->prepare(
            'UPDATE social_post_queue
             SET status = :status, external_post_id = :ext_id,
                 last_error = :err, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt2->execute([
            'status' => $newStatus,
            'ext_id' => $result['external_post_id'] ?? null,
            'err'    => $result['error'] ?? null,
            'id'     => (int) $queueItem['id'],
        ]);
    }

    return $result;
}

// ---------------------------------------------------------------------------
// Platform Image Requirements
// ---------------------------------------------------------------------------

/**
 * Return per-platform image requirements keyed by image_type.
 * Each entry: max_file_size (bytes), recommended_width, recommended_height,
 *             aspect_ratio_w, aspect_ratio_h, max_width, max_height.
 * A null dimension means no strict requirement.
 *
 * @return array<string, array<string, array<string, int|null>>>
 */
function get_social_image_requirements(): array
{
    return [
        'YouTube' => [
            'thumbnail' => [
                'recommended_width'  => 1280,
                'recommended_height' => 720,
                'aspect_ratio_w'     => 16,
                'aspect_ratio_h'     => 9,
                'max_width'          => null,
                'max_height'         => null,
                'max_file_size'      => 2 * 1024 * 1024,   // 2 MB
            ],
        ],
        'YouTube Shorts' => [
            'cover' => [
                'recommended_width'  => 1080,
                'recommended_height' => 1920,
                'aspect_ratio_w'     => 9,
                'aspect_ratio_h'     => 16,
                'max_width'          => null,
                'max_height'         => null,
                'max_file_size'      => 2 * 1024 * 1024,   // 2 MB
            ],
            'thumbnail' => [
                'recommended_width'  => 1280,
                'recommended_height' => 720,
                'aspect_ratio_w'     => 16,
                'aspect_ratio_h'     => 9,
                'max_width'          => null,
                'max_height'         => null,
                'max_file_size'      => 2 * 1024 * 1024,
            ],
        ],
        'TikTok' => [
            'cover' => [
                'recommended_width'  => 1080,
                'recommended_height' => 1920,
                'aspect_ratio_w'     => 9,
                'aspect_ratio_h'     => 16,
                'max_width'          => null,
                'max_height'         => null,
                'max_file_size'      => 10 * 1024 * 1024,  // 10 MB
            ],
        ],
        'Instagram Reels' => [
            'cover' => [
                'recommended_width'  => 1080,
                'recommended_height' => 1920,
                'aspect_ratio_w'     => 9,
                'aspect_ratio_h'     => 16,
                'max_width'          => null,
                'max_height'         => null,
                'max_file_size'      => 8 * 1024 * 1024,   // 8 MB
            ],
        ],
        'Facebook Reels' => [
            'cover' => [
                'recommended_width'  => 1080,
                'recommended_height' => 1920,
                'aspect_ratio_w'     => 9,
                'aspect_ratio_h'     => 16,
                'max_width'          => null,
                'max_height'         => null,
                'max_file_size'      => 10 * 1024 * 1024,
            ],
        ],
        'X' => [
            'thumbnail' => [
                'recommended_width'  => 1200,
                'recommended_height' => 675,
                'aspect_ratio_w'     => 16,
                'aspect_ratio_h'     => 9,
                'max_width'          => null,
                'max_height'         => null,
                'max_file_size'      => 5 * 1024 * 1024,   // 5 MB
            ],
            'profile' => [
                'recommended_width'  => 400,
                'recommended_height' => 400,
                'aspect_ratio_w'     => 1,
                'aspect_ratio_h'     => 1,
                'max_width'          => null,
                'max_height'         => null,
                'max_file_size'      => 2 * 1024 * 1024,
            ],
            'banner' => [
                'recommended_width'  => 1500,
                'recommended_height' => 500,
                'aspect_ratio_w'     => 3,
                'aspect_ratio_h'     => 1,
                'max_width'          => null,
                'max_height'         => null,
                'max_file_size'      => 5 * 1024 * 1024,
            ],
        ],
    ];
}

/**
 * Validate an image against the requirements for a given platform and image type.
 *
 * @param string   $platform   Platform name (e.g. 'YouTube')
 * @param string   $imageType  Image type key (e.g. 'thumbnail')
 * @param int|null $width      Detected image width in pixels (null = unknown)
 * @param int|null $height     Detected image height in pixels (null = unknown)
 * @param int|null $fileSize   File size in bytes (null = unknown)
 * @return array{is_valid: bool, errors: string[]}
 */
function validate_social_image(string $platform, string $imageType, ?int $width, ?int $height, ?int $fileSize): array
{
    $errors = [];
    $allRequirements = get_social_image_requirements();
    $req = $allRequirements[$platform][$imageType] ?? null;

    if ($req === null) {
        // No specific requirements defined — treat as valid.
        return ['is_valid' => true, 'errors' => []];
    }

    // File size check
    if ($fileSize !== null && $req['max_file_size'] !== null && $fileSize > $req['max_file_size']) {
        $maxMb = number_format($req['max_file_size'] / (1024 * 1024), 1);
        $actualMb = number_format($fileSize / (1024 * 1024), 1);
        $errors[] = "File size {$actualMb} MB exceeds the {$maxMb} MB limit for {$platform} {$imageType}.";
    }

    if ($width !== null && $height !== null && $width > 0 && $height > 0) {
        // Aspect ratio check — allow 1% tolerance for rounding
        $ratioW = $req['aspect_ratio_w'];
        $ratioH = $req['aspect_ratio_h'];
        if ($ratioW !== null && $ratioH !== null) {
            $expected = $ratioW / $ratioH;
            $actual   = $width / $height;
            if (abs($actual - $expected) / $expected > 0.01) {
                $errors[] = "Aspect ratio {$width}×{$height} does not match the required {$ratioW}:{$ratioH} for {$platform} {$imageType}.";
            }
        }

        // Recommended dimension check (warning-level only — flags as invalid)
        $recW = $req['recommended_width'];
        $recH = $req['recommended_height'];
        if ($recW !== null && $recH !== null && ($width !== $recW || $height !== $recH)) {
            $errors[] = "Dimensions {$width}×{$height} differ from the recommended {$recW}×{$recH} for {$platform} {$imageType}.";
        }
    } elseif ($width === null || $height === null) {
        $errors[] = "Image dimensions could not be detected.";
    }

    return [
        'is_valid' => empty($errors),
        'errors'   => $errors,
    ];
}

// ---------------------------------------------------------------------------
// YouTube — full documentary upload
// ---------------------------------------------------------------------------

function post_to_youtube(array $item): array
{
    // TODO: Integrate YouTube Data API v3
    // Scopes needed: https://www.googleapis.com/auth/youtube.upload
    // SDK: google/apiclient
    //
    // Example flow:
    //   $client = new Google\Client();
    //   $client->setAuthConfig(json_decode($account['auth_config_json'], true));
    //   $youtube = new Google\Service\YouTube($client);
    //   $video = new Google\Service\YouTube\Video();
    //   ... upload via $youtube->videos->insert(...)

    error_log('[PTMD Social] YouTube post queued but API not wired. Queue ID: ' . $item['id']);

    return [
        'ok'               => false,
        'external_post_id' => null,
        'error'            => 'TODO: YouTube API integration not configured.',
    ];
}

// ---------------------------------------------------------------------------
// YouTube Shorts
// ---------------------------------------------------------------------------

function post_to_youtube_shorts(array $item): array
{
    // TODO: Same YouTube Data API v3 — Shorts are standard YouTube uploads
    // but with #Shorts in the description and vertical aspect ratio video.
    // Clip asset_path should point to a vertical (9:16) processed clip.

    error_log('[PTMD Social] YouTube Shorts post queued but API not wired. Queue ID: ' . $item['id']);

    return [
        'ok'               => false,
        'external_post_id' => null,
        'error'            => 'TODO: YouTube Shorts API integration not configured.',
    ];
}

// ---------------------------------------------------------------------------
// TikTok
// ---------------------------------------------------------------------------

function post_to_tiktok(array $item): array
{
    // TODO: TikTok Content Posting API
    // Docs: https://developers.tiktok.com/doc/content-posting-api-get-started
    // Requires: TIKTOK_CLIENT_KEY, TIKTOK_CLIENT_SECRET, user access token
    // Flow: Initialize upload → transfer video → publish

    error_log('[PTMD Social] TikTok post queued but API not wired. Queue ID: ' . $item['id']);

    return [
        'ok'               => false,
        'external_post_id' => null,
        'error'            => 'TODO: TikTok API integration not configured.',
    ];
}

// ---------------------------------------------------------------------------
// Instagram Reels (via Meta Graph API)
// ---------------------------------------------------------------------------

function post_to_instagram_reels(array $item): array
{
    // TODO: Meta Graph API — Instagram Content Publishing
    // Docs: https://developers.facebook.com/docs/instagram-api/content-publishing
    // Steps:
    //   1. POST /{ig-user-id}/media  with video_url, media_type=REELS, caption
    //   2. Poll /{creation-id} until status_code = FINISHED
    //   3. POST /{ig-user-id}/media_publish  with creation_id

    error_log('[PTMD Social] Instagram Reels post queued but API not wired. Queue ID: ' . $item['id']);

    return [
        'ok'               => false,
        'external_post_id' => null,
        'error'            => 'TODO: Instagram Reels (Meta Graph API) not configured.',
    ];
}

// ---------------------------------------------------------------------------
// Facebook Reels (via Meta Graph API)
// ---------------------------------------------------------------------------

function post_to_facebook_reels(array $item): array
{
    // TODO: Meta Graph API — Facebook Reels Publishing
    // Docs: https://developers.facebook.com/docs/video-api/reels/reels-publishing
    // Steps:
    //   1. POST /{page-id}/video_reels with upload_phase=start
    //   2. Upload video binary to the provided upload_url
    //   3. POST /{page-id}/video_reels with upload_phase=finish and description/title

    error_log('[PTMD Social] Facebook Reels post queued but API not wired. Queue ID: ' . $item['id']);

    return [
        'ok'               => false,
        'external_post_id' => null,
        'error'            => 'TODO: Facebook Reels (Meta Graph API) not configured.',
    ];
}

// ---------------------------------------------------------------------------
// X / Twitter (via X API v2)
// ---------------------------------------------------------------------------

function post_to_x(array $item): array
{
    // TODO: X API v2 — Media upload + tweet creation
    // Docs: https://developer.x.com/en/docs/x-api
    // Steps:
    //   1. POST https://upload.twitter.com/1.1/media/upload.json  (chunked MEDIA_UPLOAD)
    //   2. POST https://api.twitter.com/2/tweets  with media.media_ids + text

    error_log('[PTMD Social] X post queued but API not wired. Queue ID: ' . $item['id']);

    return [
        'ok'               => false,
        'external_post_id' => null,
        'error'            => 'TODO: X (Twitter) API v2 integration not configured.',
    ];
}

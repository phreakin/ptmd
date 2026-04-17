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
// Dispatcher — routes a queue item to the correct platform handler
// ---------------------------------------------------------------------------

/**
 * Site-key → handler function registry.
 * site_key is the stable lowercase-underscore identifier from posting_sites.site_key
 * and is produced by ptmd_platform_to_site_key() in inc/functions.php.
 *
 * @var array<string, callable>
 */
const PTMD_SITE_DISPATCH_REGISTRY = [
    'youtube'          => 'post_to_youtube',
    'youtube_shorts'   => 'post_to_youtube_shorts',
    'tiktok'           => 'post_to_tiktok',
    'instagram_reels'  => 'post_to_instagram_reels',
    'facebook_reels'   => 'post_to_facebook_reels',
    'x'                => 'post_to_x',
];

function dispatch_social_post(array $queueItem): array
{
    $platform = (string) ($queueItem['platform'] ?? '');
    // Normalise display name or pre-formatted site_key to registry key
    $siteKey  = strtolower(str_replace(' ', '_', trim($platform)));

    $handler = PTMD_SITE_DISPATCH_REGISTRY[$siteKey] ?? null;

    if ($handler === null) {
        $result = ['ok' => false, 'external_post_id' => null, 'error' => 'Unknown platform: ' . $platform];
    } else {
        $result = $handler($queueItem);
    }

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

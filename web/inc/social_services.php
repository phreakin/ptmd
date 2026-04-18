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
    $dispatchStart = hrtime(true); // nanoseconds
    $correlationId = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    $platform = strtolower(str_replace([' ', '-'], '_', $queueItem['platform'] ?? ''));

    $result = match ($platform) {
        'youtube'             => post_to_youtube($queueItem),
        'youtube_shorts'      => post_to_youtube_shorts($queueItem),
        'tiktok'              => post_to_tiktok($queueItem),
        'instagram_reels'     => post_to_instagram_reels($queueItem),
        'facebook_reels'      => post_to_facebook_reels($queueItem),
        'snapchat_spotlight'  => post_to_snapchat_spotlight($queueItem),
        'x'                   => post_to_x($queueItem),
        'pinterest_idea_pins' => post_to_pinterest_idea_pins($queueItem),
        default               => ['ok' => false, 'error' => 'Unknown platform: ' . $queueItem['platform']],
    };

    $latencyMs    = (int) round((hrtime(true) - $dispatchStart) / 1_000_000);
    $retryAttempt = max(0, (int) ($queueItem['retry_count'] ?? 0));

    // Write log entry
    $pdo = get_db();
    if ($pdo) {
        $stmt = $pdo->prepare(
            'INSERT INTO social_post_logs
             (queue_id, platform, request_payload_json, response_payload_json,
              status, latency_ms, correlation_id, retry_attempt, created_at)
             VALUES (:queue_id, :platform, :req, :res, :status, :latency, :corr, :retry, NOW())'
        );
        $stmt->execute([
            'queue_id' => (int) $queueItem['id'],
            'platform' => $queueItem['platform'],
            'req'      => json_encode(['queue_item' => $queueItem], JSON_UNESCAPED_UNICODE),
            'res'      => json_encode($result,                       JSON_UNESCAPED_UNICODE),
            'status'   => $result['ok'] ? 'posted' : 'failed',
            'latency'  => $latencyMs,
            'corr'     => $correlationId,
            'retry'    => $retryAttempt,
        ]);

        // Classify the error for the queue row
        $newStatus  = $result['ok'] ? 'posted' : 'failed';
        $errorClass = null;
        if (!$result['ok']) {
            $err = strtolower($result['error'] ?? '');
            $errorClass = match (true) {
                str_contains($err, 'rate') || str_contains($err, '429')                                     => 'rate_limit',
                str_contains($err, 'auth') || str_contains($err, '401') || str_contains($err, '403')        => 'auth',
                str_contains($err, 'timeout') || str_contains($err, 'network') || str_contains($err, 'curl') => 'network',
                str_contains($err, 'validat') || str_contains($err, 'format')                               => 'validation',
                default                                                                                       => 'unknown',
            };
        }

        $stmt2 = $pdo->prepare(
            'UPDATE social_post_queue
             SET status = :status, external_post_id = :ext_id,
                 last_error = :err, error_class = :error_class,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt2->execute([
            'status'      => $newStatus,
            'ext_id'      => $result['external_post_id'] ?? null,
            'err'         => $result['error'] ?? null,
            'error_class' => $errorClass,
            'id'          => (int) $queueItem['id'],
        ]);
    }

    $result['correlation_id'] = $correlationId;
    $result['latency_ms']     = $latencyMs;

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

// ---------------------------------------------------------------------------
// Snapchat Spotlight
// ---------------------------------------------------------------------------

function post_to_snapchat_spotlight(array $item): array
{
    // TODO: Snapchat Content Submission API
    // Docs: https://developers.snap.com/api/content-submission
    // Steps:
    //   1. POST /v1/media/upload  with the video asset
    //   2. POST /v1/stories  with media_id + title + caption + call_to_action
    // Credentials: client_id, client_secret, refresh_token (OAuth 2.0)

    error_log('[PTMD Social] Snapchat Spotlight post queued but API not wired. Queue ID: ' . $item['id']);

    return [
        'ok'               => false,
        'external_post_id' => null,
        'error'            => 'TODO: Snapchat Spotlight API integration not configured.',
    ];
}

// ---------------------------------------------------------------------------
// Pinterest Idea Pins (via Pinterest API v5)
// ---------------------------------------------------------------------------

function post_to_pinterest_idea_pins(array $item): array
{
    // TODO: Pinterest API v5 — Idea Pin creation
    // Docs: https://developers.pinterest.com/docs/api/v5/#tag/pins
    // Steps:
    //   1. POST /v5/pins  with board_id, media.source_type=video_id, title, description
    //   2. Upload video via the media upload endpoint first to obtain a media_id
    // Credentials: app_id, app_secret, access_token, board_id

    error_log('[PTMD Social] Pinterest Idea Pins post queued but API not wired. Queue ID: ' . $item['id']);

    return [
        'ok'               => false,
        'external_post_id' => null,
        'error'            => 'TODO: Pinterest Idea Pins API integration not configured.',
    ];
}

<?php
/**
 * PTMD — Social Platform Service Stubs (inc/social_services.php)
 *
 * Modular stubs for posting content to each supported platform.
 * Each platform function:
 *  - Accepts a queue item array
 *  - Returns ['ok' => bool, 'external_post_id' => string|null, 'error' => string|null]
 *
 * The dispatcher (dispatch_social_post) handles:
 *  - Routing to the correct platform function
 *  - Idempotency check (skips already-posted items)
 *  - Latency tracking and correlation IDs
 *  - Writing to social_post_logs
 *  - Updating social_post_queue status, retry_count, error_class, and retry_after
 *
 * TODO: Replace each stub body with the real platform SDK/API call.
 *       Auth credentials for each platform are stored in social_accounts.auth_config_json.
 */

// ---------------------------------------------------------------------------
// Error class constants — used by retry/backoff logic in cron_scheduler.php
// ---------------------------------------------------------------------------

/** Transient errors (API timeouts, 5xx): exponential backoff, up to 3 attempts. */
const PTMD_ERR_TRANSIENT = 'transient';

/** Rate-limit errors (429): fixed-delay retry, up to 3 attempts. */
const PTMD_ERR_RATE_LIMIT = 'rate_limit';

/** Auth errors (token expired/revoked): no retry — requires manual intervention. */
const PTMD_ERR_AUTH = 'auth';

/** Policy rejections (content violation, banned content): no retry. */
const PTMD_ERR_POLICY = 'policy';

/** Unknown or unclassified errors: treated as transient up to 3 attempts. */
const PTMD_ERR_UNKNOWN = 'unknown';

// ---------------------------------------------------------------------------
// Retry configuration
// ---------------------------------------------------------------------------

/** Maximum number of dispatch attempts per queue item (initial + retries). */
const PTMD_MAX_RETRIES = 3;

/** Backoff delays in seconds per error class and attempt index (0-based after first failure). */
const PTMD_RETRY_DELAYS = [
    PTMD_ERR_TRANSIENT  => [60, 300, 1800],   //  1 min, 5 min, 30 min
    PTMD_ERR_RATE_LIMIT => [900, 900, 900],    // 15 min each
    PTMD_ERR_UNKNOWN    => [60, 300, 1800],
];

// ---------------------------------------------------------------------------
// Dispatcher — routes a queue item to the correct platform function
// ---------------------------------------------------------------------------

/**
 * Dispatch a queue item to the appropriate platform adapter.
 *
 * Idempotency: items already in 'posted' status are not re-dispatched.
 * On failure the queue row is updated with retry_count, error_class, and
 * retry_after so the cron scheduler knows when to next attempt the item.
 *
 * @param  array<string,mixed> $queueItem  Row from social_post_queue.
 * @return array{ok:bool, external_post_id:string|null, error:string|null}
 */
function dispatch_social_post(array $queueItem): array
{
    // Idempotency guard — never re-dispatch an already-posted item
    if (($queueItem['status'] ?? '') === 'posted') {
        return [
            'ok'               => true,
            'external_post_id' => $queueItem['external_post_id'] ?? null,
            'error'            => null,
        ];
    }

    $platform = strtolower(str_replace(' ', '_', $queueItem['platform'] ?? ''));

    $startMs = (int) (microtime(true) * 1000);

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

    $latencyMs = (int) (microtime(true) * 1000) - $startMs;

    // Derive error class and retry_after for failed dispatches
    $errorClass = null;
    $retryAfter = null;
    $retryCount = (int) ($queueItem['retry_count'] ?? 0);

    if (!$result['ok']) {
        $errorStr   = (string) ($result['error'] ?? '');
        $errorClass = classify_dispatch_error($errorStr);
        $newCount   = $retryCount + 1;

        if (in_array($errorClass, [PTMD_ERR_TRANSIENT, PTMD_ERR_RATE_LIMIT, PTMD_ERR_UNKNOWN], true)
            && $newCount < PTMD_MAX_RETRIES
        ) {
            $delays     = PTMD_RETRY_DELAYS[$errorClass] ?? PTMD_RETRY_DELAYS[PTMD_ERR_UNKNOWN];
            $delaySec   = (int) ($delays[$retryCount] ?? end($delays));
            $retryAfter = date('Y-m-d H:i:s', time() + $delaySec);
        }
    }

    // Generate a correlation ID for log traceability
    $correlationId = _ptmd_correlation_id((int) $queueItem['id']);

    // Write log entry
    $pdo = get_db();
    if ($pdo) {
        $stmt = $pdo->prepare(
            'INSERT INTO social_post_logs
             (queue_id, platform, request_payload_json, response_payload_json,
              status, latency_ms, correlation_id, retry_attempt, created_at)
             VALUES (:queue_id, :platform, :req, :res, :status, :latency, :corr, :attempt, NOW())'
        );
        $stmt->execute([
            'queue_id' => (int) $queueItem['id'],
            'platform' => $queueItem['platform'],
            'req'      => json_encode(['queue_item' => $queueItem], JSON_UNESCAPED_UNICODE),
            'res'      => json_encode($result,                       JSON_UNESCAPED_UNICODE),
            'status'   => $result['ok'] ? 'posted' : 'failed',
            'latency'  => $latencyMs,
            'corr'     => $correlationId,
            'attempt'  => $retryCount,
        ]);

        // Update queue item
        $newStatus = $result['ok'] ? 'posted' : 'failed';
        $newRetry  = $retryCount + ($result['ok'] ? 0 : 1);

        $stmt2 = $pdo->prepare(
            'UPDATE social_post_queue
             SET status           = :status,
                 external_post_id = :ext_id,
                 last_error       = :err,
                 retry_count      = :retry,
                 error_class      = :eclass,
                 retry_after      = :retry_after,
                 updated_at       = NOW()
             WHERE id = :id'
        );
        $stmt2->execute([
            'status'      => $newStatus,
            'ext_id'      => $result['external_post_id'] ?? null,
            'err'         => $result['error'] ?? null,
            'retry'       => $newRetry,
            'eclass'      => $errorClass,
            'retry_after' => $retryAfter,
            'id'          => (int) $queueItem['id'],
        ]);
    }

    return $result;
}

/**
 * Classify a dispatch error string into one of the PTMD_ERR_* constants.
 * Real adapters should return structured error codes; this function provides
 * a keyword-based fallback for stub error messages.
 *
 * @param  string $error  Error message returned by a platform adapter.
 * @return string         One of the PTMD_ERR_* constants.
 */
function classify_dispatch_error(string $error): string
{
    $lower = strtolower($error);

    if (str_contains($lower, 'auth')
        || str_contains($lower, 'token')
        || str_contains($lower, 'credential')
        || str_contains($lower, 'unauthorized')
        || str_contains($lower, 'forbidden')
    ) {
        return PTMD_ERR_AUTH;
    }

    if (str_contains($lower, 'rate limit')
        || str_contains($lower, 'rate_limit')
        || str_contains($lower, 'too many requests')
        || str_contains($lower, '429')
    ) {
        return PTMD_ERR_RATE_LIMIT;
    }

    if (str_contains($lower, 'policy')
        || str_contains($lower, 'violation')
        || str_contains($lower, 'banned')
        || str_contains($lower, 'prohibited')
        || str_contains($lower, 'spam')
    ) {
        return PTMD_ERR_POLICY;
    }

    if (str_contains($lower, 'timeout')
        || str_contains($lower, 'timed out')
        || str_contains($lower, '503')
        || str_contains($lower, '504')
        || str_contains($lower, '500')
    ) {
        return PTMD_ERR_TRANSIENT;
    }

    return PTMD_ERR_UNKNOWN;
}

/**
 * Generate a short correlation ID for log traceability.
 *
 * @param int $queueId
 * @return string  e.g. "ptmd-q42-a3f9c1"
 */
function _ptmd_correlation_id(int $queueId): string
{
    try {
        $rand = bin2hex(random_bytes(3));
    } catch (Throwable $e) {
        $rand = substr(md5(uniqid((string) $queueId, true)), 0, 6);
    }

    return 'ptmd-q' . $queueId . '-' . $rand;
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
// Snapchat Spotlight (via Snapchat Marketing API)
// ---------------------------------------------------------------------------

function post_to_snapchat_spotlight(array $item): array
{
    // TODO: Snapchat Marketing API
    // Docs: https://marketingapi.snapchat.com/docs/
    // Requires: SNAPCHAT_CLIENT_ID, SNAPCHAT_CLIENT_SECRET, user access token
    // Constraints: max 60s clip, max 32 MB, 9:16 aspect ratio, caption ≤ 250 chars
    // Flow:
    //   1. POST /v1/adaccounts/{ad_account_id}/media  to upload video
    //   2. Poll until media status = READY
    //   3. POST /v1/adaccounts/{ad_account_id}/creatives  with media_id + caption

    error_log('[PTMD Social] Snapchat Spotlight post queued but API not wired. Queue ID: ' . $item['id']);

    return [
        'ok'               => false,
        'external_post_id' => null,
        'error'            => 'TODO: Snapchat Spotlight API integration not configured.',
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
// Pinterest Idea Pins (via Pinterest API v5)
// ---------------------------------------------------------------------------

function post_to_pinterest_idea_pins(array $item): array
{
    // TODO: Pinterest API v5 — Idea Pins
    // Docs: https://developers.pinterest.com/docs/api/v5/
    // Requires: PINTEREST_APP_ID, PINTEREST_APP_SECRET, user access token
    // Constraints: max 60s, max 100 MB, 9:16, title ≤ 100 chars, description ≤ 500 chars
    // Flow:
    //   1. POST /v5/pins  with media_source.source_type = video_id
    //      (or upload via POST /v5/media for direct video upload)
    //   2. Poll pin status until media_processing_status = succeeded

    error_log('[PTMD Social] Pinterest Idea Pins post queued but API not wired. Queue ID: ' . $item['id']);

    return [
        'ok'               => false,
        'external_post_id' => null,
        'error'            => 'TODO: Pinterest Idea Pins API integration not configured.',
    ];
}

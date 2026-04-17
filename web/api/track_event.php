<?php
/**
 * PTMD API — Track Analytics Event
 *
 * Public endpoint (no auth required). Called via fetch/sendBeacon from
 * public pages to record first-party engagement events.
 *
 * POST  JSON body  { "event_type": "...", "episode_id": N?, "clip_id": N?, "extra": {}? }
 *
 * Allowed event types: page_view | video_play | video_complete | link_click
 *
 * Returns JSON. All errors return HTTP 4xx but the client should ignore failures
 * (analytics must never block page rendering).
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/analytics.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
// Allow cross-origin only from same site — not needed since same origin, but be explicit
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Parse JSON body (sendBeacon sends a Blob; fetch sends application/json)
$body = [];
$raw  = (string) file_get_contents('php://input');
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}
// Fallback to form-encoded POST if body was empty
if (!$body) {
    $body = $_POST;
}

$eventType = trim((string) ($body['event_type'] ?? ''));
$episodeId = isset($body['episode_id']) && $body['episode_id'] !== null
    ? (int) $body['episode_id'] : null;
$clipId    = isset($body['clip_id']) && $body['clip_id'] !== null
    ? (int) $body['clip_id'] : null;
$extra     = is_array($body['extra'] ?? null) ? $body['extra'] : [];

// Validate event type
$allowed = ['page_view', 'video_play', 'video_complete', 'link_click'];
if (!in_array($eventType, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid event_type']);
    exit;
}

// Sanitize IDs
if ($episodeId !== null && $episodeId <= 0) {
    $episodeId = null;
}
if ($clipId !== null && $clipId <= 0) {
    $clipId = null;
}

// Strip untrusted extra keys; keep only scalar values with short key names
$safeExtra = [];
foreach ($extra as $k => $v) {
    if (is_string($k)
        && strlen($k) <= 40
        && (is_string($v) || is_int($v) || is_float($v) || is_bool($v))
    ) {
        $safeExtra[$k] = $v;
    }
}

$ok = record_analytics_event($eventType, $episodeId, $clipId, $safeExtra);

echo json_encode(['ok' => $ok]);

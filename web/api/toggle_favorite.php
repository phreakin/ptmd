<?php
/**
 * PTMD API — Toggle Episode Favorite
 *
 * POST → toggle a viewer favorite; returns {"favorited": true|false}
 *
 * Expects JSON body: {"episode_id": <int>, "csrf_token": "<string>"}
 * Requires viewer session ($_SESSION['viewer_id']).
 * Rate limit: max 20 toggles per viewer within the last 60 seconds.
 */

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// ── Method guard ──────────────────────────────────────────────────────────────
if (!is_post()) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── Viewer auth guard ─────────────────────────────────────────────────────────
if (!is_viewer_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Login required']);
    exit;
}

// ── Parse JSON body ───────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (!verify_csrf($body['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// ── Validate episode_id ───────────────────────────────────────────────────────
$episodeId = isset($body['episode_id']) ? (int) $body['episode_id'] : 0;
if ($episodeId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid episode_id']);
    exit;
}

$viewerId = (int) $_SESSION['viewer_id'];

$pdo = get_db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
    exit;
}

// ── Rate-limit guard: max 20 toggles per viewer in last 60 s ─────────────────
$rateStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM episode_favorites
     WHERE viewer_id = :v
       AND created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)'
);
$rateStmt->execute(['v' => $viewerId]);
$recentCount = (int) $rateStmt->fetchColumn();

if ($recentCount >= 20) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Rate limit exceeded. Please slow down.']);
    exit;
}

// ── Verify episode exists ─────────────────────────────────────────────────────
$epStmt = $pdo->prepare('SELECT id FROM episodes WHERE id = :id AND status = :status LIMIT 1');
$epStmt->execute(['id' => $episodeId, 'status' => 'published']);
if (!$epStmt->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Episode not found']);
    exit;
}

// ── Toggle ────────────────────────────────────────────────────────────────────
$favorited = toggle_viewer_favorite($viewerId, $episodeId);

echo json_encode(['ok' => true, 'favorited' => $favorited]);

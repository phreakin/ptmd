<?php
/**
 * PTMD API — Chat Messages
 *
 * GET  → return approved messages (JSON)
 * POST → submit a new message (JSON response)
 */

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db();
if (!$pdo) {
    echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
    exit;
}

// ── GET: Fetch approved messages ──────────────────────────────────────────────
if (!is_post()) {
    $stmt = $pdo->prepare(
        'SELECT id, username, message, emojis_json, created_at
         FROM chat_messages
         WHERE status = "approved"
         ORDER BY created_at ASC
         LIMIT 100'
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();

    echo json_encode([
        'ok'       => true,
        'messages' => $rows,
    ]);
    exit;
}

// ── POST: Submit a message ────────────────────────────────────────────────────
if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$username = trim(strip_tags((string) ($_POST['username'] ?? '')));
$message  = trim(strip_tags((string) ($_POST['message']  ?? '')));

if (strlen($username) < 1 || strlen($username) > 50) {
    echo json_encode(['ok' => false, 'error' => 'Username must be 1–50 characters.']);
    exit;
}

if (strlen($message) < 1 || strlen($message) > 500) {
    echo json_encode(['ok' => false, 'error' => 'Message must be 1–500 characters.']);
    exit;
}

// Extract basic emoji list from message (Unicode emoji ranges)
preg_match_all('/[\x{1F300}-\x{1FAD6}\x{2600}-\x{27BF}]/u', $message, $emojiMatches);
$emojisJson = !empty($emojiMatches[0]) ? json_encode($emojiMatches[0]) : null;

// Hash IP for soft moderation (no raw IPs stored)
$ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');

$stmt = $pdo->prepare(
    'INSERT INTO chat_messages (username, message, status, emojis_json, ip_hash, created_at, updated_at)
     VALUES (:username, :message, "approved", :emojis, :ip, NOW(), NOW())'
);

$stmt->execute([
    'username' => $username,
    'message'  => $message,
    'emojis'   => $emojisJson,
    'ip'       => $ipHash,
]);

echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);

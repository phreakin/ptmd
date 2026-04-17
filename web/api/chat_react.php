<?php
/**
 * PTMD API — Chat Reactions
 *
 * POST → toggle an emoji reaction on a message (add if absent, remove if present).
 *        Requires a signed-in registered chat user.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/chat_auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_post()) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

if (!is_chat_logged_in() || !chat_user_has_role('registered')) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sign in to react to messages.']);
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$pdo = get_db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
    exit;
}

$messageId = (int) ($_POST['message_id'] ?? 0);
$reaction  = trim((string) ($_POST['reaction'] ?? ''));

if ($messageId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid message.']);
    exit;
}

// Accept only short emoji strings (1–8 bytes covers most emoji including ZWJ sequences)
if (mb_strlen($reaction, 'UTF-8') < 1 || mb_strlen($reaction, 'UTF-8') > 8) {
    echo json_encode(['ok' => false, 'error' => 'Invalid reaction.']);
    exit;
}

$userId = (int) $_SESSION['chat_user_id'];

// Toggle: remove if already reacted, add if not
$checkStmt = $pdo->prepare(
    'SELECT id FROM chat_reactions
     WHERE message_id = :mid AND chat_user_id = :uid AND reaction = :r
     LIMIT 1'
);
$checkStmt->execute(['mid' => $messageId, 'uid' => $userId, 'r' => $reaction]);
$existing = $checkStmt->fetch();

if ($existing) {
    $pdo->prepare('DELETE FROM chat_reactions WHERE id = :id')->execute(['id' => $existing['id']]);
    $added = false;
} else {
    $pdo->prepare(
        'INSERT INTO chat_reactions (message_id, chat_user_id, reaction, created_at)
         VALUES (:mid, :uid, :r, NOW())'
    )->execute(['mid' => $messageId, 'uid' => $userId, 'r' => $reaction]);
    $added = true;
}

// Return updated counts for this message
$countsStmt = $pdo->prepare(
    'SELECT reaction, COUNT(*) AS cnt
     FROM   chat_reactions
     WHERE  message_id = :mid
     GROUP  BY reaction
     ORDER  BY cnt DESC'
);
$countsStmt->execute(['mid' => $messageId]);
$counts = $countsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

echo json_encode([
    'ok'     => true,
    'added'  => $added,
    'counts' => $counts,
]);

<?php
/**
 * PTMD API — Chat Moderation
 *
 * POST → perform a moderation action (pin, delete, mute, ban, etc.)
 *        Requires moderator+ role on the calling chat user.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/chat_auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_post()) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

if (!is_chat_logged_in() || !is_chat_moderator()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Moderator access required.']);
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

$action       = trim((string) ($_POST['action']         ?? ''));
$messageId    = (int) ($_POST['message_id']    ?? 0);
$targetUserId = (int) ($_POST['target_user_id'] ?? 0);
$reason       = trim(strip_tags((string) ($_POST['reason']     ?? '')));
$expiresAt    = trim((string) ($_POST['expires_at'] ?? ''));
$roomId       = (int) ($_POST['room_id']       ?? 0);

$caller   = current_chat_user();
$callerId = $caller ? (int) $caller['id'] : 0;
$hideReason = trim(strip_tags((string) ($_POST['hide_reason'] ?? $reason)));
$allowed = [
    'pin', 'unpin', 'delete', 'restore', 'highlight',
    'mute_user', 'unmute_user', 'ban_user', 'unban_user',
    'hide', 'unhide', 'add_strike',
];

if (!in_array($action, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid action.']);
    exit;
}

// Only admins may act on other moderators+
if (in_array($action, ['mute_user', 'ban_user', 'add_strike', 'hide', 'delete'], true) && $targetUserId > 0) {
    $targetRow = get_chat_user_by_id($targetUserId);
    if ($targetRow && chat_role_rank($targetRow['role']) >= chat_role_rank('moderator')) {
        if (!chat_user_has_role('admin')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Only admins can moderate other moderators.']);
            exit;
        }
    }
}

switch ($action) {
    case 'pin':
    case 'unpin':
        if ($messageId <= 0) { echo json_encode(['ok' => false, 'error' => 'message_id required']); exit; }
        $pinned = $action === 'pin' ? 1 : 0;
        $pdo->prepare('UPDATE chat_messages SET is_pinned = :p, updated_at = NOW() WHERE id = :id')
            ->execute(['p' => $pinned, 'id' => $messageId]);
        _cml_log($pdo, $messageId, $callerId, null, $action, $reason);
        echo json_encode(['ok' => true, 'is_pinned' => (bool) $pinned]);
        break;

    case 'delete':
        if ($messageId <= 0) { echo json_encode(['ok' => false, 'error' => 'message_id required']); exit; }
        $msgStmt = $pdo->prepare('SELECT chat_user_id FROM chat_messages WHERE id = :id LIMIT 1');
        $msgStmt->execute(['id' => $messageId]);
        $msgRow = $msgStmt->fetch();
        if (!$msgRow) { echo json_encode(['ok' => false, 'error' => 'Message not found']); exit; }
        $pdo->prepare(
            'UPDATE chat_messages SET deleted_at = NOW(), deleted_by = :by, updated_at = NOW() WHERE id = :id'
        )->execute(['by' => $callerId ?: null, 'id' => $messageId]);
        _cml_log($pdo, $messageId, $callerId, (int) ($msgRow['chat_user_id'] ?? 0), 'deleted', $reason);
        echo json_encode(['ok' => true]);
        break;

    case 'restore':
        if ($messageId <= 0) { echo json_encode(['ok' => false, 'error' => 'message_id required']); exit; }
        $pdo->prepare('UPDATE chat_messages SET deleted_at = NULL, deleted_by = NULL, updated_at = NOW() WHERE id = :id')
            ->execute(['id' => $messageId]);
        _cml_log($pdo, $messageId, $callerId, null, 'restored', $reason);
        echo json_encode(['ok' => true]);
        break;

    case 'highlight':
        if ($messageId <= 0) { echo json_encode(['ok' => false, 'error' => 'message_id required']); exit; }
        $highlightColor = trim((string) ($_POST['highlight_color'] ?? ''));
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $highlightColor)) {
            $highlightColor = '#FFD60A';
        }
        $highlightAmount = (float) ($_POST['highlight_amount'] ?? 0);
        $highlightAmount = $highlightAmount > 0 ? round($highlightAmount, 2) : null;
        $pdo->prepare(
            'UPDATE chat_messages
             SET is_highlighted = 1, highlight_color = :color, highlight_amount = :amount, updated_at = NOW()
             WHERE id = :id'
        )->execute([
            'color'  => $highlightColor,
            'amount' => $highlightAmount,
            'id'     => $messageId,
        ]);
        _cml_log($pdo, $messageId, $callerId, null, 'highlighted', $reason);
        echo json_encode([
            'ok' => true,
            'message' => [
                'id' => $messageId,
                'is_highlighted' => true,
                'highlight_color' => $highlightColor,
                'highlight_amount' => $highlightAmount,
            ],
        ]);
        break;

    case 'mute_user':
        if ($targetUserId <= 0) { echo json_encode(['ok' => false, 'error' => 'target_user_id required']); exit; }
        $mutedUntil = null;
        if ($expiresAt !== '' && strtotime($expiresAt) !== false) {
            $mutedUntil = date('Y-m-d H:i:s', strtotime($expiresAt));
        }
        $pdo->prepare(
            'UPDATE chat_users SET status = "muted", muted_until = :until, updated_at = NOW() WHERE id = :id'
        )->execute(['until' => $mutedUntil, 'id' => $targetUserId]);
        _cml_log($pdo, null, $callerId, $targetUserId, 'muted_user', $reason);
        echo json_encode(['ok' => true, 'user' => ['id' => $targetUserId, 'status' => 'muted', 'muted_until' => $mutedUntil]]);
        break;

    case 'unmute_user':
        if ($targetUserId <= 0) { echo json_encode(['ok' => false, 'error' => 'target_user_id required']); exit; }
        $pdo->prepare(
            'UPDATE chat_users SET status = "active", muted_until = NULL, updated_at = NOW() WHERE id = :id'
        )->execute(['id' => $targetUserId]);
        _cml_log($pdo, null, $callerId, $targetUserId, 'unmuted_user', $reason);
        echo json_encode(['ok' => true, 'user' => ['id' => $targetUserId, 'status' => 'active', 'muted_until' => null]]);
        break;

    case 'ban_user':
        if ($targetUserId <= 0) { echo json_encode(['ok' => false, 'error' => 'target_user_id required']); exit; }
        $expiresAtVal = null;
        if ($expiresAt !== '' && strtotime($expiresAt) !== false) {
            $expiresAtVal = date('Y-m-d H:i:s', strtotime($expiresAt));
        }
        $roomIdVal = $roomId > 0 ? $roomId : null;
        if ($roomIdVal === null) {
            // Global ban
            $pdo->prepare('UPDATE chat_users SET status = "banned", updated_at = NOW() WHERE id = :id')
                ->execute(['id' => $targetUserId]);
        }
        $pdo->prepare(
            'INSERT INTO chat_user_bans (chat_user_id, room_id, banned_by, reason, expires_at, created_at)
             VALUES (:uid, :room, :by, :reason, :exp, NOW())'
        )->execute([
            'uid'    => $targetUserId,
            'room'   => $roomIdVal,
            'by'     => $callerId,
            'reason' => $reason ?: null,
            'exp'    => $expiresAtVal,
        ]);
        _cml_log($pdo, null, $callerId, $targetUserId, 'banned_user', $reason);
        echo json_encode(['ok' => true, 'user' => ['id' => $targetUserId, 'status' => $roomIdVal === null ? 'banned' : 'active']]);
        break;

    case 'unban_user':
        if ($targetUserId <= 0) { echo json_encode(['ok' => false, 'error' => 'target_user_id required']); exit; }
        $roomIdVal = $roomId > 0 ? $roomId : null;
        $pdo->prepare(
            'DELETE FROM chat_user_bans
             WHERE chat_user_id = :uid
               AND ((:room IS NULL AND room_id IS NULL) OR room_id = :room2)'
        )->execute(['uid' => $targetUserId, 'room' => $roomIdVal, 'room2' => $roomIdVal]);
        if ($roomIdVal === null) {
            $pdo->prepare('UPDATE chat_users SET status = "active", updated_at = NOW() WHERE id = :id AND status = "banned"')
                ->execute(['id' => $targetUserId]);
        }
        _cml_log($pdo, null, $callerId, $targetUserId, 'unbanned_user', $reason);
        echo json_encode(['ok' => true, 'user' => ['id' => $targetUserId, 'status' => 'active']]);
        break;

    case 'hide':
        if ($messageId <= 0) { echo json_encode(['ok'=>false,'error'=>'message_id required']); exit; }
        // Load message author for rank check
        $msgStmt = $pdo->prepare('SELECT chat_user_id FROM chat_messages WHERE id = :id LIMIT 1');
        $msgStmt->execute(['id' => $messageId]);
        $msgRow = $msgStmt->fetch();
        if (!$msgRow) { echo json_encode(['ok'=>false,'error'=>'Message not found']); exit; }
        $msgAuthor = $msgRow['chat_user_id'] ? get_chat_user_by_id((int)$msgRow['chat_user_id']) : null;
        if (!chat_can('hide', $caller, $msgAuthor ?? ['role'=>'guest'])) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Insufficient rank to hide this message.']);
            exit;
        }
        $pdo->prepare(
            'UPDATE chat_messages SET hidden_at = NOW(), hidden_by = :by, hide_reason = :reason, updated_at = NOW() WHERE id = :id'
        )->execute(['by' => $callerId ?: null, 'reason' => $hideReason ?: null, 'id' => $messageId]);
        _cml_log($pdo, $messageId, $callerId, (int)($msgRow['chat_user_id'] ?? 0), 'hidden', $reason);
        echo json_encode(['ok' => true]);
        break;

    case 'unhide':
        if ($messageId <= 0) { echo json_encode(['ok'=>false,'error'=>'message_id required']); exit; }
        $pdo->prepare(
            'UPDATE chat_messages SET hidden_at = NULL, hidden_by = NULL, hide_reason = NULL, updated_at = NOW() WHERE id = :id'
        )->execute(['id' => $messageId]);
        _cml_log($pdo, $messageId, $callerId, null, 'unhidden', $reason);
        echo json_encode(['ok' => true]);
        break;

    case 'add_strike':
        if ($targetUserId <= 0) { echo json_encode(['ok'=>false,'error'=>'target_user_id required']); exit; }
        $targetRow = get_chat_user_by_id($targetUserId);
        if (!chat_can('add_strike', $caller, $targetRow)) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'Insufficient rank to strike this user.']);
            exit;
        }
        $pdo->prepare(
            'UPDATE chat_users SET strike_count = strike_count + 1, last_strike_at = NOW(), updated_at = NOW() WHERE id = :id'
        )->execute(['id' => $targetUserId]);
        // Auto-mute on 3 strikes
        $newCount = (int)$pdo->query("SELECT strike_count FROM chat_users WHERE id = {$targetUserId} LIMIT 1")->fetchColumn();
        if ($newCount >= 3) {
            $pdo->prepare('UPDATE chat_users SET status = "muted", muted_until = DATE_ADD(NOW(), INTERVAL 24 HOUR), updated_at = NOW() WHERE id = :id')
                ->execute(['id' => $targetUserId]);
        }
        _cml_log($pdo, $messageId > 0 ? $messageId : null, $callerId, $targetUserId, 'strike_added', $reason);
        echo json_encode(['ok' => true, 'strike_count' => $newCount, 'auto_muted' => $newCount >= 3]);
        break;
}

/** Insert a moderation log row. */
function _cml_log(\PDO $pdo, ?int $messageId, int $moderatorId, ?int $targetUserId, string $action, string $reason): void
{
    $pdo->prepare(
        'INSERT INTO chat_moderation_logs
             (chat_message_id, moderator_id, target_user_id, action, reason, created_at)
         VALUES (:msg, :mod, :target, :action, :reason, NOW())'
    )->execute([
        'msg'    => $messageId   ?: null,
        'mod'    => $moderatorId ?: null,
        'target' => $targetUserId ?: null,
        'action' => $action,
        'reason' => $reason ?: null,
    ]);
}

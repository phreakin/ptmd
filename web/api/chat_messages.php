<?php
/**
 * PTMD API — Chat Messages
 *
 * GET  → return approved messages for a room (JSON)
 * POST → submit a new message (JSON response)
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/chat_auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db();
if (!$pdo) {
    echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
    exit;
}

// ── GET: Fetch approved messages ──────────────────────────────────────────────
if (!is_post()) {
    $roomSlug = trim(strip_tags((string) ($_GET['room'] ?? 'case-chat')));
    $since    = max(0, (int) ($_GET['since'] ?? 0));
    $limit    = min(100, max(10, (int) ($_GET['limit'] ?? 60)));

    // Resolve room
    $roomStmt = $pdo->prepare('SELECT * FROM chat_rooms WHERE slug = :slug AND is_archived = 0 LIMIT 1');
    $roomStmt->execute(['slug' => $roomSlug]);
    $room   = $roomStmt->fetch() ?: null;
    $roomId = $room ? (int) $room['id'] : null;

    // members_only gate
    if ($room && $room['members_only'] && !is_chat_logged_in()) {
        echo json_encode([
            'ok'           => false,
            'error'        => 'This room is members only. Please sign in to view messages.',
            'members_only' => true,
        ]);
        exit;
    }

    $sinceClause = $since > 0 ? 'AND m.id > :since' : '';
    $roomClause  = $roomId !== null ? 'AND m.room_id = :room_id' : 'AND m.room_id IS NULL';

    $stmt = $pdo->prepare("
        SELECT
            m.id, m.username, m.message, m.status, m.emojis_json, m.created_at,
            m.chat_user_id, m.parent_id,
            m.is_pinned, m.is_highlighted, m.highlight_color, m.highlight_amount,
            cu.display_name, cu.avatar_color, cu.role AS user_role, cu.badge_label
        FROM  chat_messages m
        LEFT  JOIN chat_users cu ON cu.id = m.chat_user_id
        WHERE m.status = 'approved'
          AND m.deleted_at IS NULL
          {$sinceClause}
          {$roomClause}
        ORDER BY m.created_at ASC, m.id ASC
        LIMIT :lim
    ");
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    if ($since > 0)      { $stmt->bindValue(':since',   $since,  PDO::PARAM_INT); }
    if ($roomId !== null) { $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT); }
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Attach reaction counts
    foreach ($rows as &$row) {
        $rcStmt = $pdo->prepare(
            'SELECT reaction, COUNT(*) AS cnt
             FROM   chat_reactions
             WHERE  message_id = :id
             GROUP  BY reaction
             ORDER  BY cnt DESC'
        );
        $rcStmt->execute(['id' => $row['id']]);
        $row['reactions']      = $rcStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $row['is_pinned']      = (bool) $row['is_pinned'];
        $row['is_highlighted'] = (bool) $row['is_highlighted'];
    }
    unset($row);

    // Pinned messages for this room
    $pinnedClause = $roomId !== null ? 'AND m.room_id = :room_id' : 'AND m.room_id IS NULL';
    $pinnedStmt   = $pdo->prepare("
        SELECT m.id, m.username, m.message, m.created_at,
               cu.display_name, cu.avatar_color, cu.role AS user_role
        FROM   chat_messages m
        LEFT   JOIN chat_users cu ON cu.id = m.chat_user_id
        WHERE  m.is_pinned = 1
          AND  m.deleted_at IS NULL
          AND  m.status = 'approved'
          {$pinnedClause}
        ORDER  BY m.created_at DESC
        LIMIT  3
    ");
    if ($roomId !== null) { $pinnedStmt->bindValue(':room_id', $roomId, PDO::PARAM_INT); }
    $pinnedStmt->execute();
    $pinned = $pinnedStmt->fetchAll();

    echo json_encode([
        'ok'       => true,
        'messages' => $rows,
        'pinned'   => $pinned,
        'room'     => $room ? [
            'id'                => (int) $room['id'],
            'slug'              => $room['slug'],
            'name'              => $room['name'],
            'is_live'           => (bool) $room['is_live'],
            'slow_mode_seconds' => (int)  $room['slow_mode_seconds'],
            'members_only'      => (bool) $room['members_only'],
        ] : null,
    ]);
    exit;
}

// ── POST: Submit a message ────────────────────────────────────────────────────
if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$roomSlug = trim(strip_tags((string) ($_POST['room'] ?? 'case-chat')));

// Resolve room
$roomStmt = $pdo->prepare('SELECT * FROM chat_rooms WHERE slug = :slug AND is_archived = 0 LIMIT 1');
$roomStmt->execute(['slug' => $roomSlug]);
$room = $roomStmt->fetch() ?: null;

// Fallback: first active room
if (!$room) {
    $room = $pdo->query('SELECT * FROM chat_rooms WHERE is_archived = 0 ORDER BY id ASC LIMIT 1')->fetch() ?: null;
}

$roomId = $room ? (int) $room['id'] : null;

// members_only gate
if ($room && $room['members_only'] && !is_chat_logged_in()) {
    echo json_encode(['ok' => false, 'error' => 'This room is members only. Please sign in.', 'members_only' => true]);
    exit;
}

// Auth / status checks
$chatUser   = current_chat_user();
$chatUserId = $chatUser ? (int) $chatUser['id'] : null;

if ($chatUser) {
    $guard = chat_user_send_guard($roomId);
    if ($guard !== null) {
        echo json_encode(['ok' => false, 'error' => $guard]);
        exit;
    }
}

// Username: use display_name for registered users, form input for anonymous
if ($chatUser) {
    $username = $chatUser['display_name'];
} else {
    $username = trim(strip_tags((string) ($_POST['username'] ?? '')));
    if (strlen($username) < 1 || strlen($username) > 50) {
        echo json_encode(['ok' => false, 'error' => 'Username must be 1–50 characters.']);
        exit;
    }
}

$message = trim(strip_tags((string) ($_POST['message'] ?? '')));
if (strlen($message) < 1 || strlen($message) > 500) {
    echo json_encode(['ok' => false, 'error' => 'Message must be 1–500 characters.']);
    exit;
}

// Slow mode
if ($room && (int) $room['slow_mode_seconds'] > 0) {
    $slowSec = (int) $room['slow_mode_seconds'];
    if ($chatUserId) {
        $lmStmt = $pdo->prepare('SELECT last_message_at FROM chat_users WHERE id = :id LIMIT 1');
        $lmStmt->execute(['id' => $chatUserId]);
        $lmAt = $lmStmt->fetchColumn();
    } else {
        $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
        $lmStmt = $pdo->prepare(
            'SELECT created_at FROM chat_messages WHERE ip_hash = :ip ORDER BY created_at DESC LIMIT 1'
        );
        $lmStmt->execute(['ip' => $ipHash]);
        $lmAt = $lmStmt->fetchColumn();
    }
    if ($lmAt && (time() - strtotime((string) $lmAt)) < $slowSec) {
        $wait = $slowSec - (time() - strtotime((string) $lmAt));
        echo json_encode(['ok' => false, 'error' => "Slow mode: wait {$wait} more second(s).", 'slow_mode' => true, 'wait' => $wait]);
        exit;
    }
}

// Validate parent_id
$parentId = (int) ($_POST['parent_id'] ?? 0);
if ($parentId > 0) {
    $pStmt = $pdo->prepare('SELECT id FROM chat_messages WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $pStmt->execute(['id' => $parentId]);
    if (!$pStmt->fetch()) {
        $parentId = 0;
    }
}

// Highlight (registered+ only)
$isHighlighted     = 0;
$highlightColor    = null;
$highlightAmount   = null;
if ($chatUser && !empty($_POST['highlight_color'])) {
    $hc = trim((string) $_POST['highlight_color']);
    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $hc)) {
        $isHighlighted  = 1;
        $highlightColor = $hc;
        $ha = (float) ($_POST['highlight_amount'] ?? 0);
        $highlightAmount = $ha > 0 ? round($ha, 2) : null;
    }
}

// Extract emojis
preg_match_all('/[\x{1F300}-\x{1FAD6}\x{2600}-\x{27BF}]/u', $message, $emojiMatches);
$emojisJson = !empty($emojiMatches[0]) ? json_encode($emojiMatches[0]) : null;

// Hash IP
$ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');

$stmt = $pdo->prepare(
    'INSERT INTO chat_messages
         (username, message, status, emojis_json, ip_hash,
          chat_user_id, room_id, parent_id,
          is_highlighted, highlight_color, highlight_amount,
          created_at, updated_at)
     VALUES
         (:username, :message, "approved", :emojis, :ip,
          :user_id, :room_id, :parent_id,
          :highlighted, :hcolor, :hamount,
          NOW(), NOW())'
);
$stmt->execute([
    'username'    => $username,
    'message'     => $message,
    'emojis'      => $emojisJson,
    'ip'          => $ipHash,
    'user_id'     => $chatUserId,
    'room_id'     => $roomId,
    'parent_id'   => $parentId > 0 ? $parentId : null,
    'highlighted' => $isHighlighted,
    'hcolor'      => $highlightColor,
    'hamount'     => $highlightAmount,
]);

$newId = (int) $pdo->lastInsertId();

// Update last_message_at for slow mode tracking
if ($chatUserId) {
    $pdo->prepare('UPDATE chat_users SET last_message_at = NOW(), updated_at = NOW() WHERE id = :id')
        ->execute(['id' => $chatUserId]);
}

echo json_encode(['ok' => true, 'id' => $newId]);


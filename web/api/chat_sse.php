<?php
/**
 * PTMD API — Chat Server-Sent Events
 *
 * GET ?room=<slug>[&since=<last_id>]
 *
 * Streams new chat messages as SSE. Falls back gracefully to polling if
 * the client cannot maintain a connection. Max 58-second lifetime per
 * connection; the client should reconnect immediately on close.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/chat_auth.php';

// Disable output buffering
while (ob_get_level()) ob_end_clean();

$roomSlug = trim(strip_tags((string) ($_GET['room'] ?? 'case-chat')));
$since    = max(0, (int) ($_GET['since'] ?? 0));

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no');   // Nginx: disable proxy buffering

$pdo = get_db();
if (!$pdo) {
    echo "event: error\ndata: " . json_encode(['error' => 'Database unavailable']) . "\n\n";
    flush();
    exit;
}

$isMod = is_chat_logged_in() && is_chat_moderator();

// Resolve room
$roomStmt = $pdo->prepare('SELECT * FROM chat_rooms WHERE slug = :slug AND is_archived = 0 LIMIT 1');
$roomStmt->execute(['slug' => $roomSlug]);
$room   = $roomStmt->fetch() ?: null;
$roomId = $room ? (int) $room['id'] : null;

if ($room && $room['members_only'] && !is_chat_logged_in()) {
    echo "event: error\ndata: " . json_encode(['error' => 'Members only', 'members_only' => true]) . "\n\n";
    flush();
    exit;
}

set_time_limit(65);

$maxTime       = time() + 58;
$nextHeartbeat = time() + 20;
$hiddenClause  = $isMod ? '' : 'AND m.hidden_at IS NULL';
$roomClause    = $roomId !== null ? 'AND m.room_id = :room_id' : 'AND m.room_id IS NULL';

// Send initial connection event
echo "event: connected\ndata: " . json_encode(['room' => $roomSlug, 'since' => $since]) . "\n\n";
flush();

while (!connection_aborted() && time() < $maxTime) {
    $sinceClause = $since > 0 ? 'AND m.id > :since' : '';

    $stmt = $pdo->prepare("
        SELECT m.id, m.username, m.message, m.status, m.emojis_json, m.created_at,
               m.chat_user_id, m.parent_id,
               m.is_pinned, m.is_highlighted, m.highlight_color, m.highlight_amount,
               m.hidden_at, m.hide_reason,
               cu.display_name, cu.avatar_color, cu.role AS user_role, cu.badge_label
        FROM   chat_messages m
        LEFT   JOIN chat_users cu ON cu.id = m.chat_user_id
        WHERE  m.status = 'approved'
          AND  m.deleted_at IS NULL
          {$hiddenClause}
          {$sinceClause}
          {$roomClause}
        ORDER BY m.created_at ASC, m.id ASC
        LIMIT 50
    ");

    if ($since > 0)       $stmt->bindValue(':since',   $since,  PDO::PARAM_INT);
    if ($roomId !== null) $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if ($rows) {
        foreach ($rows as &$row) {
            $rcStmt = $pdo->prepare(
                'SELECT reaction, COUNT(*) AS cnt FROM chat_reactions WHERE message_id = :id GROUP BY reaction ORDER BY cnt DESC'
            );
            $rcStmt->execute(['id' => $row['id']]);
            $row['reactions']      = $rcStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $row['is_pinned']      = (bool) $row['is_pinned'];
            $row['is_highlighted'] = (bool) $row['is_highlighted'];
            $row['is_hidden']      = !empty($row['hidden_at']);
        }
        unset($row);

        $since = (int) end($rows)['id'];
        echo "event: messages\ndata: " . json_encode(['messages' => $rows]) . "\n\n";
        flush();
    }

    if (time() >= $nextHeartbeat) {
        echo ": heartbeat\n\n";
        flush();
        $nextHeartbeat = time() + 20;
    }

    sleep(2);
}

echo "event: reconnect\ndata: {}\n\n";
flush();

<?php
/**
 * PTMD API — Chat Donations
 *
 * GET  ?room=<slug>  → returns donation links and config
 * POST               → logs a donation intent (link-click redirect tracker)
 *
 * No payment processing is performed here. Users are redirected to
 * the external payment platform (PayPal.me, Venmo, CashApp).
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/chat_auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
    exit;
}

// ── Resolve donation settings ─────────────────────────────────────────────────
$paypal  = trim(site_setting('chat_paypal_me', ''));
$venmo   = trim(site_setting('chat_venmo_handle', ''));
$cashapp = trim(site_setting('chat_cashapp_handle', ''));
$msg     = site_setting('chat_donation_message', 'Help keep the investigation going!');
$goal    = site_setting('chat_donation_goal', '');

$links = [];
if ($paypal  !== '') $links['paypal']  = 'https://www.paypal.me/' . rawurlencode(ltrim($paypal, '@/'));
if ($venmo   !== '') $links['venmo']   = 'https://venmo.com/' . rawurlencode(ltrim($venmo, '@'));
if ($cashapp !== '') $links['cashapp'] = 'https://cash.app/$' . rawurlencode(ltrim($cashapp, '$'));

// ── GET ───────────────────────────────────────────────────────────────────────
if (!is_post()) {
    $roomSlug = trim(strip_tags((string) ($_GET['room'] ?? 'case-chat')));
    $roomStmt = $pdo->prepare('SELECT id, donations_enabled FROM chat_rooms WHERE slug = :slug AND is_archived = 0 LIMIT 1');
    $roomStmt->execute(['slug' => $roomSlug]);
    $room = $roomStmt->fetch() ?: null;

    echo json_encode([
        'ok'                => true,
        'donations_enabled' => $room ? (bool) $room['donations_enabled'] : false,
        'message'           => $msg,
        'goal'              => $goal,
        'links'             => $links,
    ]);
    exit;
}

// ── POST: Log donation intent ─────────────────────────────────────────────────
if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$platform = trim(strtolower((string) ($_POST['platform'] ?? '')));
if (!in_array($platform, ['paypal', 'venmo', 'cashapp'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid platform.']);
    exit;
}

if (!isset($links[$platform])) {
    echo json_encode(['ok' => false, 'error' => 'This donation platform is not configured.']);
    exit;
}

$chatUser    = current_chat_user();
$chatUserId  = $chatUser ? (int) $chatUser['id'] : null;
$displayName = $chatUser ? $chatUser['display_name'] : trim(strip_tags((string) ($_POST['display_name'] ?? '')));
$message     = trim(strip_tags(substr((string) ($_POST['message'] ?? ''), 0, 500)));
$roomSlug    = trim(strip_tags((string) ($_POST['room'] ?? 'case-chat')));

$roomStmt = $pdo->prepare('SELECT id, donations_enabled FROM chat_rooms WHERE slug = :slug AND is_archived = 0 LIMIT 1');
$roomStmt->execute(['slug' => $roomSlug]);
$room = $roomStmt->fetch() ?: null;

if (!$room || !$room['donations_enabled']) {
    echo json_encode(['ok' => false, 'error' => 'Donations are not enabled for this room.']);
    exit;
}

$ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');

$pdo->prepare(
    'INSERT INTO chat_donations (chat_user_id, display_name, platform, message, room_id, ip_hash, created_at)
     VALUES (:uid, :name, :platform, :msg, :room, :ip, NOW())'
)->execute([
    'uid'      => $chatUserId,
    'name'     => $displayName ?: null,
    'platform' => $platform,
    'msg'      => $message ?: null,
    'room'     => (int) $room['id'],
    'ip'       => $ipHash,
]);

echo json_encode([
    'ok'           => true,
    'redirect_url' => $links[$platform],
    'platform'     => $platform,
]);

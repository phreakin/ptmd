<?php
/**
 * PTMD API — Chat Users
 *
 * GET  ?page=&per_page=&role=&status=&q=   → paginated user list
 * POST action=set_role                     → change user role (admin+)
 * POST action=reset_strikes                → reset strike count (admin+)
 *
 * Sensitive fields (email, ip) only exposed to admin+ users.
 * Public callers see: id, display_name, username, role, badge_label, status, created_at.
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

$caller   = current_chat_user();
$isAdmin  = $caller && chat_can('manage_roles', $caller);
$isMod    = $caller && chat_can('view_audit', $caller);

// ── GET ───────────────────────────────────────────────────────────────────────
if (!is_post()) {
    $page    = max(1, (int) ($_GET['page']     ?? 1));
    $perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 50)));
    $offset  = ($page - 1) * $perPage;
    $role    = trim((string) ($_GET['role']   ?? ''));
    $status  = trim((string) ($_GET['status'] ?? ''));
    $q       = trim((string) ($_GET['q']      ?? ''));

    $validRoles    = ['guest','registered','moderator','admin','super_admin'];
    $validStatuses = ['active','muted','banned'];

    $where  = ['1=1'];
    $params = [];

    if ($role !== '' && in_array($role, $validRoles, true)) {
        $where[] = 'role = :role'; $params['role'] = $role;
    }
    if ($status !== '' && in_array($status, $validStatuses, true)) {
        $where[] = 'status = :status'; $params['status'] = $status;
    }
    if ($q !== '') {
        $where[] = '(username LIKE :q OR display_name LIKE :q)';
        $params['q'] = '%' . $q . '%';
    }

    $whereSQL = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM chat_users WHERE {$whereSQL}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $cols = $isAdmin
        ? 'id, username, email, display_name, avatar_color, role, status, muted_until, badge_label, strike_count, trust_level, last_strike_at, last_message_at, created_at, updated_at'
        : 'id, username, display_name, avatar_color, role, status, badge_label, created_at';

    $stmt = $pdo->prepare("SELECT {$cols} FROM chat_users WHERE {$whereSQL} ORDER BY created_at DESC LIMIT :lim OFFSET :off");
    foreach ($params as $k => $v) $stmt->bindValue(':' . $k, $v);
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll();

    echo json_encode([
        'ok'       => true,
        'users'    => $users,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int) ceil($total / $perPage),
    ]);
    exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────
if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}
if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin access required.']);
    exit;
}

$action       = trim((string) ($_POST['action'] ?? ''));
$targetUserId = (int) ($_POST['target_user_id'] ?? 0);

if ($targetUserId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'target_user_id required']);
    exit;
}

$targetRow = get_chat_user_by_id($targetUserId);
if (!$targetRow) {
    echo json_encode(['ok' => false, 'error' => 'User not found']);
    exit;
}

if (!chat_can('manage_roles', $caller, $targetRow)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Insufficient rank.']);
    exit;
}

if ($action === 'set_role') {
    $newRole = trim((string) ($_POST['role'] ?? ''));
    $validR  = ['guest','registered','moderator','admin','super_admin'];
    if (!in_array($newRole, $validR, true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid role.']);
        exit;
    }
    // Cannot elevate to own rank or higher unless super_admin
    if (chat_role_rank($newRole) >= chat_role_rank($caller['role']) && $caller['role'] !== 'super_admin') {
        echo json_encode(['ok' => false, 'error' => 'Cannot assign a role equal to or higher than your own.']);
        exit;
    }
    $pdo->prepare('UPDATE chat_users SET role = :role, updated_at = NOW() WHERE id = :id')
        ->execute(['role' => $newRole, 'id' => $targetUserId]);
    echo json_encode(['ok' => true, 'role' => $newRole]);
    exit;
}

if ($action === 'reset_strikes') {
    $pdo->prepare('UPDATE chat_users SET strike_count = 0, last_strike_at = NULL, updated_at = NOW() WHERE id = :id')
        ->execute(['id' => $targetUserId]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Invalid action.']);

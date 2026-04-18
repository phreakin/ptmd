<?php
/**
 * PTMD API — Chat Roles
 *
 * GET → returns role hierarchy, descriptions, and full permissions matrix.
 *       Public endpoint — no auth required.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/chat_auth.php';

header('Content-Type: application/json; charset=utf-8');

if (is_post()) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'GET only']);
    exit;
}

$roles = [
    [
        'role'        => 'guest',
        'rank'        => chat_role_rank('guest'),
        'label'       => 'Guest',
        'description' => 'Anonymous visitor. Can post with a display alias. No reactions or replies.',
        'color'       => '#94A3B8',
    ],
    [
        'role'        => 'registered',
        'rank'        => chat_role_rank('registered'),
        'label'       => 'Member',
        'description' => 'Registered chat account. Can react, reply, and highlight messages.',
        'color'       => '#2EC4B6',
    ],
    [
        'role'        => 'moderator',
        'rank'        => chat_role_rank('moderator'),
        'label'       => 'Moderator',
        'description' => 'Can hide/delete messages, mute/ban users, and run trivia. Cannot act on admins.',
        'color'       => '#38BDF8',
    ],
    [
        'role'        => 'admin',
        'rank'        => chat_role_rank('admin'),
        'label'       => 'Admin',
        'description' => 'Full chat management including room settings, moderator oversight, and role assignments.',
        'color'       => '#FFD60A',
    ],
    [
        'role'        => 'super_admin',
        'rank'        => chat_role_rank('super_admin'),
        'label'       => 'Super Admin',
        'description' => 'Highest privilege. Can act on all users including admins. Full system access.',
        'color'       => '#F97316',
    ],
];

$pdo = get_db();
// Append live counts if DB is available
if ($pdo) {
    $counts = $pdo->query('SELECT role, COUNT(*) AS cnt FROM chat_users GROUP BY role')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($roles as &$r) {
        $r['user_count'] = (int) ($counts[$r['role']] ?? 0);
    }
    unset($r);
}

echo json_encode([
    'ok'          => true,
    'roles'       => $roles,
    'permissions' => chat_permissions_matrix(),
]);

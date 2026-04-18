<?php
/**
 * PTMD Admin — Chat User Management
 *
 * View, search, and manage registered chat users: change roles, mute, ban,
 * and reset the mute/ban status on a user.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$pageTitle      = 'Chat Users | PTMD Admin';
$activePage     = 'chat-users';
$pageHeading    = 'Chat Users';
$pageSubheading = 'View and manage registered chat user accounts.';

$pdo = get_db();

// ── POST actions ───────────────────────────────────────────────────────────────
if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/chat-users.php', 'Invalid CSRF token.', 'danger');
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    $userId = (int) ($_POST['user_id'] ?? 0);

    if ($userId > 0) {
        switch ($action) {
            case 'set_role':
                $role = trim((string) ($_POST['role'] ?? ''));
                $validRoles = ['registered', 'moderator', 'admin', 'super_admin'];
                if (in_array($role, $validRoles, true)) {
                    $pdo->prepare('UPDATE chat_users SET role = :role, updated_at = NOW() WHERE id = :id')
                        ->execute(['role' => $role, 'id' => $userId]);
                    redirect('/admin/chat-users.php?' . http_build_query($_GET), 'Role updated.', 'success');
                }
                break;

            case 'set_badge':
                $badge = trim(strip_tags((string) ($_POST['badge_label'] ?? '')));
                $pdo->prepare('UPDATE chat_users SET badge_label = :badge, updated_at = NOW() WHERE id = :id')
                    ->execute(['badge' => $badge !== '' ? $badge : null, 'id' => $userId]);
                redirect('/admin/chat-users.php?' . http_build_query($_GET), 'Badge updated.', 'success');
                break;

            case 'mute':
                $hours = max(0, (int) ($_POST['mute_hours'] ?? 0));
                $mutedUntil = $hours > 0
                    ? date('Y-m-d H:i:s', strtotime("+{$hours} hours"))
                    : null;
                $pdo->prepare(
                    'UPDATE chat_users SET status = "muted", muted_until = :until, updated_at = NOW() WHERE id = :id'
                )->execute(['until' => $mutedUntil, 'id' => $userId]);
                redirect('/admin/chat-users.php?' . http_build_query($_GET), 'User muted.', 'success');
                break;

            case 'unmute':
                $pdo->prepare(
                    'UPDATE chat_users SET status = "active", muted_until = NULL, updated_at = NOW() WHERE id = :id'
                )->execute(['id' => $userId]);
                redirect('/admin/chat-users.php?' . http_build_query($_GET), 'User unmuted.', 'success');
                break;

            case 'ban':
                $pdo->prepare(
                    'UPDATE chat_users SET status = "banned", updated_at = NOW() WHERE id = :id'
                )->execute(['id' => $userId]);
                redirect('/admin/chat-users.php?' . http_build_query($_GET), 'User banned.', 'success');
                break;

            case 'unban':
                $pdo->prepare(
                    'UPDATE chat_users SET status = "active", updated_at = NOW() WHERE id = :id'
                )->execute(['id' => $userId]);
                // Clear any ban records too
                $pdo->prepare('DELETE FROM chat_user_bans WHERE chat_user_id = :id AND room_id IS NULL')
                    ->execute(['id' => $userId]);
                redirect('/admin/chat-users.php?' . http_build_query($_GET), 'User unbanned.', 'success');
                break;
        }
    }
}

// ── Filters / search ──────────────────────────────────────────────────────────
$search      = trim(strip_tags((string) ($_GET['q']      ?? '')));
$filterRole  = trim((string) ($_GET['role']   ?? 'all'));
$filterStatus = trim((string) ($_GET['status'] ?? 'all'));
$validRoles   = ['all', 'registered', 'moderator', 'admin', 'super_admin'];
$validStatuses = ['all', 'active', 'muted', 'banned'];
if (!in_array($filterRole,   $validRoles,    true)) $filterRole   = 'all';
if (!in_array($filterStatus, $validStatuses, true)) $filterStatus = 'all';

// ── Build query ───────────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($search !== '') {
    $where[]          = '(username LIKE :q OR display_name LIKE :q OR email LIKE :q)';
    $params['q']      = '%' . $search . '%';
}
if ($filterRole !== 'all') {
    $where[]          = 'role = :role';
    $params['role']   = $filterRole;
}
if ($filterStatus !== 'all') {
    $where[]           = 'status = :status';
    $params['status']  = $filterStatus;
}

$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$users      = [];
$totalCount = 0;

if ($pdo) {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM chat_users {$whereSQL}");
    $countStmt->execute($params);
    $totalCount = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT id, username, display_name, email, role, status, muted_until, badge_label,
                last_message_at, created_at
         FROM chat_users
         {$whereSQL}
         ORDER BY created_at DESC
         LIMIT 200"
    );
    $stmt->execute($params);
    $users = $stmt->fetchAll();
}

$pageActions = '<a href="/admin/chat.php" class="btn btn-ptmd-outline btn-sm"><i class="fa-solid fa-comments me-1"></i>Message Queue</a>';

include __DIR__ . '/_admin_head.php';
?>

<!-- ── Filter bar ─────────────────────────────────────────────────────────── -->
<div class="d-flex flex-wrap gap-3 mb-4 align-items-end">
    <form method="get" action="/admin/chat-users.php" class="d-flex gap-2 align-items-center flex-wrap flex-grow-1">
        <input type="text" class="form-control form-control-sm" name="q"
               placeholder="Search username, display name, email…"
               value="<?php ee($search); ?>" style="max-width:280px">

        <select class="form-select form-select-sm" name="role" style="width:auto">
            <?php foreach (['all' => 'All Roles', 'registered' => 'Registered', 'moderator' => 'Moderator', 'admin' => 'Admin', 'super_admin' => 'Super Admin'] as $val => $lbl): ?>
                <option value="<?php ee($val); ?>" <?php echo $filterRole === $val ? 'selected' : ''; ?>><?php ee($lbl); ?></option>
            <?php endforeach; ?>
        </select>

        <select class="form-select form-select-sm" name="status" style="width:auto">
            <?php foreach (['all' => 'All Statuses', 'active' => 'Active', 'muted' => 'Muted', 'banned' => 'Banned'] as $val => $lbl): ?>
                <option value="<?php ee($val); ?>" <?php echo $filterStatus === $val ? 'selected' : ''; ?>><?php ee($lbl); ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn-ptmd-teal btn-sm">
            <i class="fa-solid fa-magnifying-glass me-1"></i>Filter
        </button>
        <?php if ($search !== '' || $filterRole !== 'all' || $filterStatus !== 'all'): ?>
            <a href="/admin/chat-users.php" class="btn btn-ptmd-ghost btn-sm">Clear</a>
        <?php endif; ?>
    </form>

    <span class="ptmd-muted small ms-auto">
        <?php echo number_format($totalCount); ?> user<?php echo $totalCount !== 1 ? 's' : ''; ?> found
    </span>
</div>

<!-- ── Users table ────────────────────────────────────────────────────────── -->
<div class="ptmd-panel p-0 overflow-hidden">
    <?php if (empty($users)): ?>
        <p class="ptmd-muted p-lg">No users match the current filters.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Message</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u):
                        $isBanned = $u['status'] === 'banned';
                        $isMuted  = $u['status'] === 'muted';
                        $roleSlug = str_replace('_', '-', $u['role']);
                    ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="ptmd-chat-avatar flex-shrink-0" style="--avatar-color:#2EC4B6;width:30px;height:30px;font-size:var(--text-xs)">
                                        <?php echo e(strtoupper(substr($u['display_name'], 0, 1))); ?>
                                    </div>
                                    <div>
                                        <div class="fw-600 small"><?php ee($u['display_name']); ?></div>
                                        <code class="ptmd-muted" style="font-size:var(--text-xs)">@<?php ee($u['username']); ?></code>
                                        <?php if ($u['email']): ?>
                                            <div class="ptmd-muted" style="font-size:var(--text-xs)"><?php ee($u['email']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($u['badge_label']): ?>
                                            <span class="ptmd-chat-role-badge ptmd-chat-role-badge--<?php ee($roleSlug); ?> ms-1">
                                                <?php ee($u['badge_label']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>

                            <!-- Role -->
                            <td>
                                <form method="post" action="/admin/chat-users.php?<?php echo http_build_query($_GET); ?>" class="d-inline">
                                    <?php csrf_input(); ?>
                                    <input type="hidden" name="action"  value="set_role">
                                    <input type="hidden" name="user_id" value="<?php ee($u['id']); ?>">
                                    <select class="form-select form-select-sm" name="role" style="width:auto;font-size:var(--text-xs)"
                                            onchange="this.form.submit()">
                                        <?php foreach (['registered', 'moderator', 'admin', 'super_admin'] as $r): ?>
                                            <option value="<?php ee($r); ?>" <?php echo $u['role'] === $r ? 'selected' : ''; ?>>
                                                <?php ee(ucfirst(str_replace('_', ' ', $r))); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>

                            <!-- Status -->
                            <td>
                                <span class="ptmd-status ptmd-status-<?php ee($u['status']); ?>">
                                    <?php ee(ucfirst($u['status'])); ?>
                                </span>
                                <?php if ($isMuted && $u['muted_until']): ?>
                                    <div class="ptmd-muted" style="font-size:var(--text-xs)">
                                        Until <?php echo e(date('M j g:ia', strtotime($u['muted_until']))); ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td class="ptmd-muted small">
                                <?php echo $u['last_message_at'] ? e(date('M j, Y', strtotime($u['last_message_at']))) : '—'; ?>
                            </td>

                            <td class="ptmd-muted small">
                                <?php echo e(date('M j, Y', strtotime($u['created_at']))); ?>
                            </td>

                            <!-- Actions -->
                            <td>
                                <div class="d-flex gap-2 flex-wrap align-items-center">
                                    <!-- Badge label -->
                                    <form method="post" action="/admin/chat-users.php?<?php echo http_build_query($_GET); ?>"
                                          class="d-flex gap-1" style="max-width:200px">
                                        <?php csrf_input(); ?>
                                        <input type="hidden" name="action"  value="set_badge">
                                        <input type="hidden" name="user_id" value="<?php ee($u['id']); ?>">
                                        <input type="text" class="form-control form-control-sm" name="badge_label"
                                               maxlength="50" placeholder="Badge…"
                                               value="<?php ee($u['badge_label'] ?? ''); ?>"
                                               style="font-size:var(--text-xs);width:110px"
                                               data-tippy-content="Custom badge label">
                                        <button type="submit" class="btn btn-ptmd-ghost btn-sm"
                                                data-tippy-content="Save badge">
                                            <i class="fa-solid fa-tag"></i>
                                        </button>
                                    </form>

                                    <!-- Mute -->
                                    <?php if (!$isMuted && !$isBanned): ?>
                                        <form method="post" action="/admin/chat-users.php?<?php echo http_build_query($_GET); ?>"
                                              class="d-flex gap-1 align-items-center">
                                            <?php csrf_input(); ?>
                                            <input type="hidden" name="action"  value="mute">
                                            <input type="hidden" name="user_id" value="<?php ee($u['id']); ?>">
                                            <select name="mute_hours" class="form-select form-select-sm"
                                                    style="width:auto;font-size:var(--text-xs)">
                                                <option value="1">1h</option>
                                                <option value="24">24h</option>
                                                <option value="168">7d</option>
                                                <option value="0">Perm</option>
                                            </select>
                                            <button type="submit" class="btn btn-ptmd-ghost btn-sm"
                                                    style="color:var(--ptmd-warning)"
                                                    data-tippy-content="Mute user">
                                                <i class="fa-solid fa-microphone-slash"></i>
                                            </button>
                                        </form>
                                    <?php elseif ($isMuted): ?>
                                        <form method="post" action="/admin/chat-users.php?<?php echo http_build_query($_GET); ?>">
                                            <?php csrf_input(); ?>
                                            <input type="hidden" name="action"  value="unmute">
                                            <input type="hidden" name="user_id" value="<?php ee($u['id']); ?>">
                                            <button type="submit" class="btn btn-ptmd-teal btn-sm"
                                                    data-tippy-content="Unmute user">
                                                <i class="fa-solid fa-microphone me-1"></i>Unmute
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- Ban / Unban -->
                                    <?php if (!$isBanned): ?>
                                        <form method="post" action="/admin/chat-users.php?<?php echo http_build_query($_GET); ?>">
                                            <?php csrf_input(); ?>
                                            <input type="hidden" name="action"  value="ban">
                                            <input type="hidden" name="user_id" value="<?php ee($u['id']); ?>">
                                            <button type="submit" class="btn btn-ptmd-ghost btn-sm"
                                                    style="color:var(--ptmd-error)"
                                                    data-confirm="Ban this user? They will be unable to chat."
                                                    data-tippy-content="Ban user">
                                                <i class="fa-solid fa-ban"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="/admin/chat-users.php?<?php echo http_build_query($_GET); ?>">
                                            <?php csrf_input(); ?>
                                            <input type="hidden" name="action"  value="unban">
                                            <input type="hidden" name="user_id" value="<?php ee($u['id']); ?>">
                                            <button type="submit" class="btn btn-ptmd-teal btn-sm"
                                                    data-tippy-content="Unban user">
                                                <i class="fa-solid fa-circle-check me-1"></i>Unban
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- View messages -->
                                    <a href="/admin/chat.php?status=all"
                                       class="btn btn-ptmd-ghost btn-sm"
                                       data-tippy-content="View messages">
                                        <i class="fa-solid fa-comments"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_admin_footer.php'; ?>

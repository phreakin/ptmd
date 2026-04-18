<?php
/**
 * PTMD Admin — Chat Users
 * Manage chat user accounts: roles, bans, mutes, strikes.
 */

$pageTitle      = 'Chat Users | PTMD Admin';
$activePage     = 'chat-users';
$pageHeading    = 'Chat Users';
$pageSubheading = 'Manage roles, bans, mutes, and trust levels for registered chat accounts.';

include __DIR__ . '/_admin_head.php';

$pdo = get_db();

// ── POST: role/ban/mute/strike actions ────────────────────────────────────────
if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/chat-users.php', 'Invalid CSRF token.', 'danger');
    }
    $targetId = (int) ($_POST['target_id'] ?? 0);
    $action   = trim((string) ($_POST['action'] ?? ''));
    $adminId  = (int) ($_SESSION['admin_user_id'] ?? 0);

    if ($targetId > 0) {
        switch ($action) {
            case 'set_role':
                $newRole = trim((string) ($_POST['role'] ?? ''));
                $valid   = ['guest','registered','moderator','admin','super_admin'];
                if (in_array($newRole, $valid, true)) {
                    $pdo->prepare('UPDATE chat_users SET role = :r, updated_at = NOW() WHERE id = :id')
                        ->execute(['r' => $newRole, 'id' => $targetId]);
                }
                break;
            case 'ban':
                $pdo->prepare("UPDATE chat_users SET status = 'banned', updated_at = NOW() WHERE id = :id")
                    ->execute(['id' => $targetId]);
                $pdo->prepare('INSERT INTO chat_user_bans (chat_user_id, room_id, banned_by, reason, expires_at, created_at) VALUES (:uid, NULL, :by, "Admin action", NULL, NOW())')
                    ->execute(['uid' => $targetId, 'by' => $adminId]);
                $pdo->prepare('INSERT INTO chat_moderation_logs (chat_message_id, moderator_id, target_user_id, action, reason, created_at) VALUES (NULL,:mod,:uid,"banned_user","Admin action",NOW())')
                    ->execute(['mod' => $adminId, 'uid' => $targetId]);
                break;
            case 'unban':
                $pdo->prepare("UPDATE chat_users SET status = 'active', updated_at = NOW() WHERE id = :id AND status = 'banned'")
                    ->execute(['id' => $targetId]);
                $pdo->prepare('DELETE FROM chat_user_bans WHERE chat_user_id = :uid AND room_id IS NULL')->execute(['uid' => $targetId]);
                break;
            case 'mute_1h':
                $pdo->prepare("UPDATE chat_users SET status = 'muted', muted_until = DATE_ADD(NOW(), INTERVAL 1 HOUR), updated_at = NOW() WHERE id = :id")
                    ->execute(['id' => $targetId]);
                break;
            case 'mute_24h':
                $pdo->prepare("UPDATE chat_users SET status = 'muted', muted_until = DATE_ADD(NOW(), INTERVAL 24 HOUR), updated_at = NOW() WHERE id = :id")
                    ->execute(['id' => $targetId]);
                break;
            case 'unmute':
                $pdo->prepare("UPDATE chat_users SET status = 'active', muted_until = NULL, updated_at = NOW() WHERE id = :id")
                    ->execute(['id' => $targetId]);
                break;
            case 'reset_strikes':
                $pdo->prepare('UPDATE chat_users SET strike_count = 0, last_strike_at = NULL, updated_at = NOW() WHERE id = :id')
                    ->execute(['id' => $targetId]);
                break;
        }
        redirect('/admin/chat-users.php?' . http_build_query(array_filter(['q' => $_GET['q'] ?? '', 'role' => $_GET['role'] ?? '', 'status' => $_GET['status'] ?? ''])), 'Action applied.', 'success');
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$q      = trim((string) ($_GET['q']      ?? ''));
$fRole  = trim((string) ($_GET['role']   ?? ''));
$fStat  = trim((string) ($_GET['status'] ?? ''));
$page   = max(1, (int) ($_GET['page']    ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

$validRoles    = ['guest','registered','moderator','admin','super_admin'];
$validStatuses = ['active','muted','banned'];

$where  = ['1=1'];
$params = [];
if ($q !== '')     { $where[] = '(u.username LIKE :q OR u.display_name LIKE :q OR u.email LIKE :q)'; $params['q'] = "%{$q}%"; }
if ($fRole !== '' && in_array($fRole, $validRoles, true))    { $where[] = 'u.role = :role';   $params['role']   = $fRole; }
if ($fStat !== '' && in_array($fStat, $validStatuses, true)) { $where[] = 'u.status = :stat'; $params['stat']   = $fStat; }

$whereSQL = implode(' AND ', $where);

$total = 0;
$users = [];
$stats = ['total' => 0];

if ($pdo) {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM chat_users u WHERE {$whereSQL}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.display_name, u.avatar_color,
               u.role, u.status, u.badge_label, u.strike_count, u.trust_level,
               u.muted_until, u.last_message_at, u.created_at,
               (SELECT COUNT(*) FROM chat_messages m WHERE m.chat_user_id = u.id AND m.deleted_at IS NULL) AS message_count
        FROM chat_users u
        WHERE {$whereSQL}
        ORDER BY u.created_at DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($params as $k => $v) $stmt->bindValue(':' . $k, $v);
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll();

    $statRows = $pdo->query('SELECT role, COUNT(*) AS cnt FROM chat_users GROUP BY role')->fetchAll();
    foreach ($statRows as $sr) $stats[$sr['role']] = (int) $sr['cnt'];
    $stats['total'] = (int) $pdo->query('SELECT COUNT(*) FROM chat_users')->fetchColumn();
}

$pages = max(1, (int) ceil($total / $perPage));

$roleBadgeStyle = [
    'super_admin' => 'background:rgba(249,115,22,.15);color:#F97316;border:1px solid rgba(249,115,22,.3)',
    'admin'       => 'background:rgba(255,214,10,.12);color:#FFD60A;border:1px solid rgba(255,214,10,.25)',
    'moderator'   => 'background:rgba(56,189,248,.12);color:#38BDF8;border:1px solid rgba(56,189,248,.25)',
    'registered'  => 'background:rgba(46,196,182,.10);color:#2EC4B6;border:1px solid rgba(46,196,182,.2)',
    'guest'       => 'background:rgba(148,163,184,.10);color:#94A3B8;border:1px solid rgba(148,163,184,.2)',
];
?>

<!-- Stats bar -->
<div class="row g-3 mb-5">
    <?php
    $statItems = [
        ['label' => 'Total Users',   'value' => $stats['total']         ?? 0, 'icon' => 'fa-users',          'color' => 'var(--ptmd-teal)'],
        ['label' => 'Super Admins',  'value' => $stats['super_admin']   ?? 0, 'icon' => 'fa-crown',          'color' => 'var(--ptmd-orange)'],
        ['label' => 'Admins',        'value' => $stats['admin']         ?? 0, 'icon' => 'fa-shield',         'color' => 'var(--ptmd-yellow)'],
        ['label' => 'Moderators',    'value' => $stats['moderator']     ?? 0, 'icon' => 'fa-gavel',          'color' => 'var(--ptmd-info)'],
        ['label' => 'Members',       'value' => $stats['registered']    ?? 0, 'icon' => 'fa-user-check',     'color' => 'var(--ptmd-teal)'],
        ['label' => 'Guests',        'value' => $stats['guest']         ?? 0, 'icon' => 'fa-user',           'color' => 'var(--ptmd-muted)'],
    ];
    foreach ($statItems as $s): ?>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="ptmd-panel p-lg text-center">
                <i class="fa-solid <?php ee($s['icon']); ?> mb-2" style="font-size:1.4rem;color:<?php echo $s['color']; ?>"></i>
                <div class="fw-700 fs-4"><?php ee((string)$s['value']); ?></div>
                <div class="ptmd-muted" style="font-size:var(--text-xs)"><?php ee($s['label']); ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Filter bar -->
<form method="get" action="/admin/chat-users.php" class="d-flex flex-wrap gap-2 mb-4 align-items-center">
    <input class="form-control form-control-sm" style="max-width:220px" name="q" value="<?php ee($q); ?>" placeholder="Search username / display name…">
    <select class="form-select form-select-sm" style="width:auto" name="role">
        <option value="">All Roles</option>
        <?php foreach ($validRoles as $r): ?>
            <option value="<?php ee($r); ?>" <?php echo $fRole === $r ? 'selected' : ''; ?>><?php ee(ucfirst(str_replace('_',' ',$r))); ?></option>
        <?php endforeach; ?>
    </select>
    <select class="form-select form-select-sm" style="width:auto" name="status">
        <option value="">All Statuses</option>
        <?php foreach ($validStatuses as $s): ?>
            <option value="<?php ee($s); ?>" <?php echo $fStat === $s ? 'selected' : ''; ?>><?php ee(ucfirst($s)); ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-ptmd-teal btn-sm" type="submit"><i class="fa-solid fa-magnifying-glass me-1"></i>Filter</button>
    <a href="/admin/chat-users.php" class="btn btn-ptmd-ghost btn-sm">Clear</a>
    <span class="ptmd-muted small ms-auto"><?php echo number_format($total); ?> user<?php echo $total !== 1 ? 's' : ''; ?></span>
</form>

<!-- User table -->
<?php if ($users): ?>
<div class="ptmd-panel" style="overflow-x:auto">
    <table class="table table-dark table-hover align-middle mb-0" style="font-size:var(--text-sm)">
        <thead>
            <tr>
                <th>User</th>
                <th>Role</th>
                <th>Status</th>
                <th>Strikes</th>
                <th>Messages</th>
                <th>Last Active</th>
                <th>Joined</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <?php
            $initials = strtoupper(substr($u['display_name'] ?: $u['username'], 0, 1));
            $isMuted  = $u['status'] === 'muted';
            $isBanned = $u['status'] === 'banned';
            $roleStyle = $roleBadgeStyle[$u['role']] ?? '';
            ?>
            <tr>
                <!-- User -->
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div style="width:32px;height:32px;border-radius:50%;background:<?php ee($u['avatar_color'] ?? '#2EC4B6'); ?>;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:var(--text-sm);color:#0B0C10;flex-shrink:0"><?php echo $initials; ?></div>
                        <div>
                            <div class="fw-600"><?php ee($u['display_name']); ?></div>
                            <div class="ptmd-muted" style="font-size:var(--text-xs)">@<?php ee($u['username']); ?></div>
                        </div>
                    </div>
                </td>
                <!-- Role -->
                <td>
                    <span class="badge" style="<?php echo $roleStyle; ?>;font-size:var(--text-xs)">
                        <?php ee(ucfirst(str_replace('_',' ',$u['role']))); ?>
                    </span>
                </td>
                <!-- Status -->
                <td>
                    <span class="badge <?php echo $isBanned ? 'bg-danger' : ($isMuted ? 'bg-warning text-dark' : 'bg-success'); ?>">
                        <?php ee(ucfirst($u['status'])); ?>
                    </span>
                    <?php if ($isMuted && $u['muted_until']): ?>
                        <div class="ptmd-muted" style="font-size:10px">until <?php echo e(date('M j g:ia', strtotime($u['muted_until']))); ?></div>
                    <?php endif; ?>
                </td>
                <!-- Strikes -->
                <td>
                    <?php if ((int)$u['strike_count'] > 0): ?>
                        <span class="badge bg-warning text-dark"><?php echo (int)$u['strike_count']; ?> ⚡</span>
                    <?php else: ?>
                        <span class="ptmd-muted">0</span>
                    <?php endif; ?>
                </td>
                <!-- Messages -->
                <td class="ptmd-muted"><?php echo number_format((int)$u['message_count']); ?></td>
                <!-- Last active -->
                <td class="ptmd-muted" style="white-space:nowrap;font-size:var(--text-xs)">
                    <?php echo $u['last_message_at'] ? e(date('M j, Y', strtotime($u['last_message_at']))) : '—'; ?>
                </td>
                <!-- Joined -->
                <td class="ptmd-muted" style="white-space:nowrap;font-size:var(--text-xs)">
                    <?php echo e(date('M j, Y', strtotime($u['created_at']))); ?>
                </td>
                <!-- Actions -->
                <td>
                    <div class="d-flex gap-1 flex-wrap">
                        <!-- Set Role -->
                        <form method="post" action="/admin/chat-users.php?<?php echo http_build_query(array_filter(['q'=>$q,'role'=>$fRole,'status'=>$fStat,'page'=>$page])); ?>" class="d-inline-flex align-items-center gap-1">
                            <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                            <input type="hidden" name="target_id" value="<?php ee((string)$u['id']); ?>">
                            <input type="hidden" name="action" value="set_role">
                            <select class="form-select form-select-sm" name="role" style="width:auto;font-size:var(--text-xs)" onchange="this.form.submit()">
                                <?php foreach ($validRoles as $r): ?>
                                    <option value="<?php ee($r); ?>" <?php echo $u['role'] === $r ? 'selected' : ''; ?>><?php ee(ucfirst(str_replace('_',' ',$r))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <!-- Mute / Unmute -->
                        <?php if (!$isBanned): ?>
                            <?php if ($isMuted): ?>
                                <form method="post" action="/admin/chat-users.php" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                    <input type="hidden" name="target_id" value="<?php ee((string)$u['id']); ?>">
                                    <input type="hidden" name="action" value="unmute">
                                    <button class="btn btn-ptmd-teal btn-sm" type="submit" data-tippy-content="Unmute">
                                        <i class="fa-solid fa-microphone"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <div class="dropdown">
                                    <button class="btn btn-ptmd-ghost btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" data-tippy-content="Mute">
                                        <i class="fa-solid fa-microphone-slash" style="color:var(--ptmd-warning)"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <form method="post" action="/admin/chat-users.php">
                                                <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                                <input type="hidden" name="target_id" value="<?php ee((string)$u['id']); ?>">
                                                <input type="hidden" name="action" value="mute_1h">
                                                <button class="dropdown-item" type="submit">Mute 1 hour</button>
                                            </form>
                                        </li>
                                        <li>
                                            <form method="post" action="/admin/chat-users.php">
                                                <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                                <input type="hidden" name="target_id" value="<?php ee((string)$u['id']); ?>">
                                                <input type="hidden" name="action" value="mute_24h">
                                                <button class="dropdown-item" type="submit">Mute 24 hours</button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <!-- Ban / Unban -->
                        <?php if ($isBanned): ?>
                            <form method="post" action="/admin/chat-users.php" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                <input type="hidden" name="target_id" value="<?php ee((string)$u['id']); ?>">
                                <input type="hidden" name="action" value="unban">
                                <button class="btn btn-ptmd-teal btn-sm" type="submit" data-tippy-content="Unban">
                                    <i class="fa-solid fa-user-check"></i>
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="/admin/chat-users.php" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                <input type="hidden" name="target_id" value="<?php ee((string)$u['id']); ?>">
                                <input type="hidden" name="action" value="ban">
                                <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                        style="color:var(--ptmd-error)"
                                        data-confirm="Ban this user from all chat rooms?"
                                        data-tippy-content="Ban User">
                                    <i class="fa-solid fa-ban"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                        <!-- Reset strikes -->
                        <?php if ((int)$u['strike_count'] > 0): ?>
                            <form method="post" action="/admin/chat-users.php" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                <input type="hidden" name="target_id" value="<?php ee((string)$u['id']); ?>">
                                <input type="hidden" name="action" value="reset_strikes">
                                <button class="btn btn-ptmd-ghost btn-sm" type="submit" data-tippy-content="Reset Strikes">
                                    <i class="fa-solid fa-rotate" style="color:var(--ptmd-warning)"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                        <!-- View messages -->
                        <a href="/admin/chat.php?user_id=<?php ee((string)$u['id']); ?>"
                           class="btn btn-ptmd-ghost btn-sm" data-tippy-content="View Messages">
                            <i class="fa-solid fa-message"></i>
                        </a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
    <div class="d-flex justify-content-center gap-2 mt-4">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
            <?php $pq = array_filter(['q'=>$q,'role'=>$fRole,'status'=>$fStat,'page'=>$p]); ?>
            <a href="/admin/chat-users.php?<?php echo http_build_query($pq); ?>"
               class="btn btn-sm <?php echo $p === $page ? 'btn-ptmd-teal' : 'btn-ptmd-outline'; ?>">
                <?php echo $p; ?>
            </a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<?php else: ?>
    <div class="ptmd-panel p-lg">
        <p class="ptmd-muted small mb-0">No users match your filters.</p>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/_admin_footer.php'; ?>

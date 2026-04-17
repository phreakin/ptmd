<?php
/**
 * PTMD Admin — Case Chat Moderation
 */
require_once __DIR__ . '/../inc/chat_auth.php';

$pageTitle      = 'Chat Moderation | PTMD Admin';
$activePage     = 'chat';
$pageHeading    = 'Case Chat Moderation';
$pageSubheading = 'Review, approve, flag, block, pin, or soft-delete chat messages.';

include __DIR__ . '/_admin_head.php';

$pdo = get_db();

if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/chat.php', 'Invalid CSRF token.', 'danger');
    }

    $msgId     = (int) ($_POST['id']     ?? 0);
    $action    = trim((string) ($_POST['action'] ?? ''));
    $modUserId = (int) ($_SESSION['admin_user_id'] ?? 0);

    if ($msgId > 0) {
        switch ($action) {
            case 'approved':
            case 'flagged':
            case 'blocked':
                $pdo->prepare('UPDATE chat_messages SET status = :s, updated_at = NOW() WHERE id = :id')
                    ->execute(['s' => $action, 'id' => $msgId]);
                $pdo->prepare(
                    'INSERT INTO chat_moderation_logs (chat_message_id, moderator_id, action, created_at)
                     VALUES (:msg, :mod, :action, NOW())'
                )->execute(['msg' => $msgId, 'mod' => $modUserId ?: null, 'action' => $action]);
                redirect('/admin/chat.php?' . http_build_query($_GET), "Message {$action}.", 'success');
                break;

            case 'delete':
                $pdo->prepare(
                    'UPDATE chat_messages SET deleted_at = NOW(), deleted_by = NULL, updated_at = NOW() WHERE id = :id'
                )->execute(['id' => $msgId]);
                $pdo->prepare(
                    'INSERT INTO chat_moderation_logs (chat_message_id, moderator_id, action, created_at)
                     VALUES (:msg, :mod, "deleted", NOW())'
                )->execute(['msg' => $msgId, 'mod' => $modUserId ?: null]);
                redirect('/admin/chat.php?' . http_build_query($_GET), 'Message soft-deleted.', 'success');
                break;

            case 'restore':
                $pdo->prepare(
                    'UPDATE chat_messages SET deleted_at = NULL, deleted_by = NULL, updated_at = NOW() WHERE id = :id'
                )->execute(['id' => $msgId]);
                $pdo->prepare(
                    'INSERT INTO chat_moderation_logs (chat_message_id, moderator_id, action, created_at)
                     VALUES (:msg, :mod, "restored", NOW())'
                )->execute(['msg' => $msgId, 'mod' => $modUserId ?: null]);
                redirect('/admin/chat.php?' . http_build_query($_GET), 'Message restored.', 'success');
                break;

            case 'pin':
            case 'unpin':
                $pinned = $action === 'pin' ? 1 : 0;
                $pdo->prepare('UPDATE chat_messages SET is_pinned = :p, updated_at = NOW() WHERE id = :id')
                    ->execute(['p' => $pinned, 'id' => $msgId]);
                $pdo->prepare(
                    'INSERT INTO chat_moderation_logs (chat_message_id, moderator_id, action, created_at)
                     VALUES (:msg, :mod, :action, NOW())'
                )->execute(['msg' => $msgId, 'mod' => $modUserId ?: null, 'action' => $action . 'ned']);
                redirect('/admin/chat.php?' . http_build_query($_GET), "Message {$action}ned.", 'success');
                break;

            case 'hide':
                $pdo->prepare('UPDATE chat_messages SET hidden_at = NOW(), hidden_by = NULL, hide_reason = :r, updated_at = NOW() WHERE id = :id')
                    ->execute(['r' => '', 'id' => $msgId]);
                $pdo->prepare('INSERT INTO chat_moderation_logs (chat_message_id, moderator_id, action, created_at) VALUES (:msg,:mod,"hidden",NOW())')
                    ->execute(['msg' => $msgId, 'mod' => $modUserId ?: null]);
                redirect('/admin/chat.php?' . http_build_query($_GET), 'Message hidden.', 'success');
                break;

            case 'unhide':
                $pdo->prepare('UPDATE chat_messages SET hidden_at = NULL, hidden_by = NULL, hide_reason = NULL, updated_at = NOW() WHERE id = :id')
                    ->execute(['id' => $msgId]);
                $pdo->prepare('INSERT INTO chat_moderation_logs (chat_message_id, moderator_id, action, created_at) VALUES (:msg,:mod,"unhidden",NOW())')
                    ->execute(['msg' => $msgId, 'mod' => $modUserId ?: null]);
                redirect('/admin/chat.php?' . http_build_query($_GET), 'Message unhidden.', 'success');
                break;
        }
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterStatus  = $_GET['status'] ?? 'all';
$filterRoom    = (int) ($_GET['room'] ?? 0);
$filterDeleted = isset($_GET['deleted']);
$filterHidden  = isset($_GET['hidden']);
$validStatuses = ['approved', 'flagged', 'blocked', 'all'];
if (!in_array($filterStatus, $validStatuses, true)) $filterStatus = 'all';

// Load rooms for filter dropdown
$rooms = $pdo ? $pdo->query('SELECT id, name FROM chat_rooms WHERE is_archived = 0 ORDER BY name ASC')->fetchAll() : [];

// Build query
$where  = [];
$params = [];
if ($filterStatus !== 'all') { $where[] = 'm.status = :status';  $params['status'] = $filterStatus; }
if ($filterRoom > 0)          { $where[] = 'm.room_id = :room_id'; $params['room_id'] = $filterRoom; }
if ($filterDeleted)           { $where[] = 'm.deleted_at IS NOT NULL'; }
else                          { $where[] = 'm.deleted_at IS NULL'; }
if ($filterHidden)            { $where[] = 'm.hidden_at IS NOT NULL'; }

$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$query    = "SELECT m.*, cu.display_name, cu.role AS user_role, cu.status AS user_status,
                    r.name AS room_name
             FROM chat_messages m
             LEFT JOIN chat_users cu ON cu.id = m.chat_user_id
             LEFT JOIN chat_rooms  r  ON r.id  = m.room_id
             {$whereSQL}
             ORDER BY m.created_at DESC LIMIT 300";

$messages = [];
if ($pdo) {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $messages = $stmt->fetchAll();
}
?>

<!-- Filter bar -->
<div class="d-flex flex-wrap gap-2 mb-4 align-items-center">
    <?php foreach (['all', 'approved', 'flagged', 'blocked'] as $tab): ?>
        <?php $q = array_merge($_GET, ['status' => $tab]); unset($q['deleted']); unset($q['hidden']); ?>
        <a href="/admin/chat.php?<?php echo http_build_query($q); ?>"
           class="btn btn-sm <?php echo $filterStatus === $tab && !$filterDeleted && !$filterHidden ? 'btn-ptmd-teal' : 'btn-ptmd-outline'; ?>">
            <?php ee(ucfirst($tab)); ?>
        </a>
    <?php endforeach; ?>
    <?php $dq = array_merge($_GET, ['deleted' => '1', 'status' => 'all']); unset($dq['hidden']); ?>
    <a href="/admin/chat.php?<?php echo http_build_query($dq); ?>"
       class="btn btn-sm <?php echo $filterDeleted ? 'btn-ptmd-teal' : 'btn-ptmd-outline'; ?>"
       style="<?php echo $filterDeleted ? '' : 'border-color:var(--ptmd-error);color:var(--ptmd-error)'; ?>">
        <i class="fa-solid fa-trash me-1"></i>Deleted
    </a>
    <?php $hq = array_merge($_GET, ['hidden' => '1', 'status' => 'all']); unset($hq['deleted']); ?>
    <a href="/admin/chat.php?<?php echo http_build_query($hq); ?>"
       class="btn btn-sm <?php echo $filterHidden ? 'btn-ptmd-teal' : 'btn-ptmd-outline'; ?>"
       style="<?php echo $filterHidden ? '' : 'border-color:var(--ptmd-warning);color:var(--ptmd-warning)'; ?>">
        <i class="fa-solid fa-eye-slash me-1"></i>Hidden
    </a>

    <?php if ($rooms): ?>
        <form method="get" action="/admin/chat.php" class="d-flex gap-2 align-items-center ms-auto">
            <input type="hidden" name="status" value="<?php ee($filterStatus); ?>">
            <?php if ($filterDeleted): ?><input type="hidden" name="deleted" value="1"><?php endif; ?>
            <select class="form-select form-select-sm" name="room" style="width:auto" onchange="this.form.submit()">
                <option value="0">All rooms</option>
                <?php foreach ($rooms as $r): ?>
                    <option value="<?php ee((string) $r['id']); ?>" <?php echo $filterRoom === (int) $r['id'] ? 'selected' : ''; ?>>
                        <?php ee($r['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</div>

<!-- Message list -->
<?php if ($messages): ?>
    <div class="d-flex flex-column gap-3">
        <?php foreach ($messages as $msg): ?>
            <?php
            $avatarColor = $msg['display_name']
                ? '#2EC4B6'
                : 'linear-gradient(135deg,var(--ptmd-teal),var(--ptmd-navy))';
            $displayName = $msg['display_name'] ?: $msg['username'];
            $isDeleted   = !empty($msg['deleted_at']);
            $isHidden    = !empty($msg['hidden_at']);
            ?>
            <div class="ptmd-panel p-lg d-flex gap-4 align-items-start <?php echo $isDeleted ? 'opacity-50' : ($isHidden ? 'opacity-75' : ''); ?>" style="<?php echo $isHidden ? 'border-left:3px solid var(--ptmd-warning)' : ''; ?>">

                <!-- Avatar -->
                <div class="ptmd-chat-avatar flex-shrink-0" style="--avatar-color:<?php echo $msg['avatar_color'] ?? '#2EC4B6'; ?>">
                    <?php echo e(strtoupper(substr($displayName, 0, 1))); ?>
                </div>

                <!-- Content -->
                <div class="flex-grow-1" style="min-width:0">
                    <div class="d-flex flex-wrap gap-3 align-items-center mb-1">
                        <strong class="ptmd-text-teal"><?php ee($displayName); ?></strong>
                        <?php if ($msg['user_role']): ?>
                            <span class="ptmd-chat-role-badge ptmd-chat-role-badge--<?php ee(str_replace('_','-',$msg['user_role'])); ?>">
                                <?php ee(ucfirst(str_replace('_',' ',$msg['user_role']))); ?>
                            </span>
                        <?php endif; ?>
                        <span class="ptmd-status ptmd-status-<?php ee($msg['status']); ?>" style="font-size:var(--text-xs)">
                            <?php ee($msg['status']); ?>
                        </span>
                        <?php if ($msg['is_pinned']): ?>
                            <span class="ptmd-muted" style="font-size:var(--text-xs);color:var(--ptmd-yellow)">
                                <i class="fa-solid fa-thumbtack me-1"></i>Pinned
                            </span>
                        <?php endif; ?>
                        <?php if ($isDeleted): ?>
                            <span style="font-size:var(--text-xs);color:var(--ptmd-error)">
                                <i class="fa-solid fa-trash me-1"></i>Deleted
                            </span>
                        <?php endif; ?>
                        <?php if ($isHidden): ?>
                            <span style="font-size:var(--text-xs);color:var(--ptmd-warning)">
                                <i class="fa-solid fa-eye-slash me-1"></i>Hidden
                            </span>
                        <?php endif; ?>
                        <span class="ptmd-muted" style="font-size:var(--text-xs)">
                            <?php echo e(date('M j, Y g:ia', strtotime($msg['created_at']))); ?>
                        </span>
                        <?php if ($msg['room_name']): ?>
                            <span class="ptmd-muted" style="font-size:var(--text-xs)">
                                <i class="fa-solid fa-hashtag me-1"></i><?php ee($msg['room_name']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <p class="ptmd-text-muted small mb-0"><?php ee($msg['message']); ?></p>
                </div>

                <!-- Actions -->
                <div class="d-flex gap-2 flex-shrink-0 flex-wrap">
                    <?php if ($isDeleted): ?>
                        <form method="post" action="/admin/chat.php?<?php echo http_build_query($_GET); ?>">
                            <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                            <input type="hidden" name="id" value="<?php ee((string) $msg['id']); ?>">
                            <input type="hidden" name="action" value="restore">
                            <button class="btn btn-ptmd-teal btn-sm" type="submit" data-tippy-content="Restore">
                                <i class="fa-solid fa-rotate-left"></i>
                            </button>
                        </form>
                    <?php else: ?>
                        <?php if ($msg['status'] !== 'approved'): ?>
                            <form method="post" action="/admin/chat.php?<?php echo http_build_query($_GET); ?>">
                                <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                <input type="hidden" name="id" value="<?php ee((string) $msg['id']); ?>">
                                <input type="hidden" name="action" value="approved">
                                <button class="btn btn-ptmd-teal btn-sm" type="submit" data-tippy-content="Approve">
                                    <i class="fa-solid fa-check"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php if ($msg['status'] !== 'flagged'): ?>
                            <form method="post" action="/admin/chat.php?<?php echo http_build_query($_GET); ?>">
                                <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                <input type="hidden" name="id" value="<?php ee((string) $msg['id']); ?>">
                                <input type="hidden" name="action" value="flagged">
                                <button class="btn btn-ptmd-outline btn-sm" type="submit"
                                        style="border-color:var(--ptmd-warning);color:var(--ptmd-warning)"
                                        data-tippy-content="Flag for review">
                                    <i class="fa-solid fa-flag"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php if ($msg['status'] !== 'blocked'): ?>
                            <form method="post" action="/admin/chat.php?<?php echo http_build_query($_GET); ?>">
                                <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                <input type="hidden" name="id" value="<?php ee((string) $msg['id']); ?>">
                                <input type="hidden" name="action" value="blocked">
                                <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                        style="color:var(--ptmd-error)"
                                        data-confirm="Block this message? It will be hidden from the public feed."
                                        data-tippy-content="Block">
                                    <i class="fa-solid fa-ban"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                        <!-- Pin / Unpin -->
                        <form method="post" action="/admin/chat.php?<?php echo http_build_query($_GET); ?>">
                            <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                            <input type="hidden" name="id" value="<?php ee((string) $msg['id']); ?>">
                            <input type="hidden" name="action" value="<?php echo $msg['is_pinned'] ? 'unpin' : 'pin'; ?>">
                            <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                    style="<?php echo $msg['is_pinned'] ? 'color:var(--ptmd-yellow)' : ''; ?>"
                                    data-tippy-content="<?php echo $msg['is_pinned'] ? 'Unpin' : 'Pin'; ?>">
                                <i class="fa-solid fa-thumbtack"></i>
                            </button>
                        </form>
                        <!-- Soft delete -->
                        <form method="post" action="/admin/chat.php?<?php echo http_build_query($_GET); ?>">
                            <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                            <input type="hidden" name="id" value="<?php ee((string) $msg['id']); ?>">
                            <input type="hidden" name="action" value="delete">
                            <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                    style="color:var(--ptmd-error)"
                                    data-confirm="Soft-delete this message? It will be hidden but recoverable."
                                    data-tippy-content="Soft Delete">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                        <!-- Hide / Unhide -->
                        <?php if ($isHidden): ?>
                            <form method="post" action="/admin/chat.php?<?php echo http_build_query($_GET); ?>">
                                <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                <input type="hidden" name="id" value="<?php ee((string) $msg['id']); ?>">
                                <input type="hidden" name="action" value="unhide">
                                <button class="btn btn-ptmd-teal btn-sm" type="submit" data-tippy-content="Unhide">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="/admin/chat.php?<?php echo http_build_query($_GET); ?>">
                                <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                <input type="hidden" name="id" value="<?php ee((string) $msg['id']); ?>">
                                <input type="hidden" name="action" value="hide">
                                <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                        style="color:var(--ptmd-warning)"
                                        data-tippy-content="Hide from public (mods still see it)">
                                    <i class="fa-solid fa-eye-slash"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="ptmd-panel p-lg">
        <p class="ptmd-muted small mb-0">No messages in this category.</p>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/_admin_footer.php'; ?>


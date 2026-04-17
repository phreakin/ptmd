<?php
/**
 * PTMD Admin — Case Chat Moderation
 */

$pageTitle    = 'Chat Moderation | PTMD Admin';
$activePage   = 'chat';
$pageHeading  = 'Case Chat Moderation';
$pageSubheading = 'Review, approve, flag, or block chat messages.';

include __DIR__ . '/_admin_head.php';

$pdo = get_db();

if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/chat.php', 'Invalid CSRF token.', 'danger');
    }

    $msgId   = (int) ($_POST['id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';
    $allowed   = ['approved','flagged','blocked'];

    if ($msgId > 0 && in_array($newStatus, $allowed, true)) {
        $pdo->prepare(
            'UPDATE chat_messages SET status = :status, updated_at = NOW() WHERE id = :id'
        )->execute(['status' => $newStatus, 'id' => $msgId]);

        // Log the moderation action
        $pdo->prepare(
            'INSERT INTO chat_moderation_logs (chat_message_id, moderator_id, action, created_at)
             VALUES (:msg, :mod, :action, NOW())'
        )->execute([
            'msg'    => $msgId,
            'mod'    => (int) ($_SESSION['admin_user_id'] ?? 0),
            'action' => $newStatus,
        ]);

        redirect('/admin/chat.php', 'Message ' . $newStatus . '.', 'success');
    }
}

$filterStatus = $_GET['status'] ?? 'approved';
$validFilter  = ['approved','flagged','blocked','all'];
if (!in_array($filterStatus, $validFilter)) {
    $filterStatus = 'approved';
}

$query  = 'SELECT * FROM chat_messages';
$params = [];
if ($filterStatus !== 'all') {
    $query  .= ' WHERE status = :status';
    $params['status'] = $filterStatus;
}
$query .= ' ORDER BY created_at DESC LIMIT 200';

$messages = $pdo ? $pdo->prepare($query) : null;
if ($messages) {
    $messages->execute($params);
    $messages = $messages->fetchAll();
} else {
    $messages = [];
}
?>

<!-- Filter tabs -->
<div class="d-flex flex-wrap gap-2 mb-5">
    <?php foreach (['approved','flagged','blocked','all'] as $tab): ?>
        <a href="/admin/chat.php?status=<?php ee($tab); ?>"
           class="btn btn-sm <?php echo $filterStatus === $tab ? 'btn-ptmd-teal' : 'btn-ptmd-outline'; ?>">
            <?php ee(ucfirst($tab)); ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Message list -->
<?php if ($messages): ?>
    <div class="d-flex flex-column gap-3">
        <?php foreach ($messages as $msg): ?>
            <div class="ptmd-panel p-lg d-flex gap-4 align-items-start">

                <!-- Avatar -->
                <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--ptmd-teal),var(--ptmd-navy));display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--ptmd-black);flex-shrink:0">
                    <?php echo e(strtoupper(substr($msg['username'], 0, 1))); ?>
                </div>

                <!-- Content -->
                <div class="flex-grow-1">
                    <div class="d-flex flex-wrap gap-3 align-items-center mb-1">
                        <strong class="ptmd-text-teal"><?php ee($msg['username']); ?></strong>
                        <span class="ptmd-status ptmd-status-<?php ee($msg['status']); ?>"
                              style="font-size:var(--text-xs)">
                            <?php ee($msg['status']); ?>
                        </span>
                        <span class="ptmd-muted" style="font-size:var(--text-xs)">
                            <?php echo e(date('M j, Y g:ia', strtotime($msg['created_at']))); ?>
                        </span>
                    </div>
                    <p class="ptmd-text-muted small mb-0"><?php ee($msg['message']); ?></p>
                </div>

                <!-- Actions -->
                <div class="d-flex gap-2 flex-shrink-0">
                    <?php if ($msg['status'] !== 'approved'): ?>
                        <form method="post" action="/admin/chat.php">
                            <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                            <input type="hidden" name="id" value="<?php ee((string) $msg['id']); ?>">
                            <input type="hidden" name="status" value="approved">
                            <button class="btn btn-ptmd-teal btn-sm" type="submit" data-tippy-content="Approve">
                                <i class="fa-solid fa-check"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ($msg['status'] !== 'flagged'): ?>
                        <form method="post" action="/admin/chat.php">
                            <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                            <input type="hidden" name="id" value="<?php ee((string) $msg['id']); ?>">
                            <input type="hidden" name="status" value="flagged">
                            <button class="btn btn-ptmd-outline btn-sm" type="submit"
                                    style="border-color:var(--ptmd-warning);color:var(--ptmd-warning)"
                                    data-tippy-content="Flag for review">
                                <i class="fa-solid fa-flag"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ($msg['status'] !== 'blocked'): ?>
                        <form method="post" action="/admin/chat.php">
                            <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                            <input type="hidden" name="id" value="<?php ee((string) $msg['id']); ?>">
                            <input type="hidden" name="status" value="blocked">
                            <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                    style="color:var(--ptmd-error)"
                                    data-confirm="Block this message? It will be hidden from the public feed."
                                    data-tippy-content="Block">
                                <i class="fa-solid fa-ban"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="ptmd-panel p-lg">
        <p class="ptmd-muted small">No messages in this category.</p>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/_admin_footer.php'; ?>

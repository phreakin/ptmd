<?php
/**
 * PTMD Admin — Social Post Queue
 */

$pageTitle    = 'Social Queue | PTMD Admin';
$activePage   = 'posts';
$pageHeading  = 'Social Post Queue';
$pageSubheading = 'Manage and track all scheduled social media posts.';

include __DIR__ . '/_admin_head.php';

require_once __DIR__ . '/../inc/social_services.php';

$pdo = get_db();

if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/posts.php', 'Invalid CSRF token.', 'danger');
    }

    $postAction = $_POST['_action'] ?? 'update_status';

    if ($postAction === 'add') {
        $pdo->prepare(
            'INSERT INTO social_post_queue (episode_id, platform, content_type, caption, asset_path, scheduled_for, status, created_at, updated_at)
             VALUES (:eid, :platform, :ct, :caption, :asset, :sched, "queued", NOW(), NOW())'
        )->execute([
            'eid'      => (int) ($_POST['episode_id'] ?? 0) ?: null,
            'platform' => $_POST['platform'] ?? '',
            'ct'       => $_POST['content_type'] ?? '',
            'caption'  => $_POST['caption'] ?? '',
            'asset'    => $_POST['asset_path'] ?? '',
            'sched'    => $_POST['scheduled_for'] ?? '',
        ]);
        redirect('/admin/posts.php', 'Post queued.', 'success');
    }

    if ($postAction === 'update_status') {
        $queueId   = (int) ($_POST['id']     ?? 0);
        $newStatus = trim((string) ($_POST['status'] ?? ''));
        $allowed   = ['draft','queued','scheduled','posted','failed','canceled'];

        if ($queueId > 0 && in_array($newStatus, $allowed, true)) {
            $pdo->prepare(
                'UPDATE social_post_queue SET status = :status, updated_at = NOW() WHERE id = :id'
            )->execute(['status' => $newStatus, 'id' => $queueId]);
            redirect('/admin/posts.php', 'Status updated.', 'success');
        }
    }

    if ($postAction === 'delete') {
        $delId = (int) ($_POST['id'] ?? 0);
        if ($delId > 0) {
            $pdo->prepare('DELETE FROM social_post_queue WHERE id = :id')->execute(['id' => $delId]);
            redirect('/admin/posts.php', 'Queue item deleted.', 'success');
        }
    }

    if ($postAction === 'publish_now') {
        $qId = (int) ($_POST['id'] ?? 0);
        if ($qId > 0) {
            $item = $pdo->prepare('SELECT * FROM social_post_queue WHERE id = :id');
            $item->execute(['id' => $qId]);
            $item = $item->fetch();
            if ($item) {
                $result = dispatch_social_post($item);
                $msg = $result['ok'] ? 'Post dispatched successfully.' : 'Dispatch failed: ' . ($result['error'] ?? 'unknown error');
                $type = $result['ok'] ? 'success' : 'warning';
                redirect('/admin/posts.php', $msg, $type);
            }
        }
    }
}

$queue = $pdo ? $pdo->query(
    'SELECT q.*, e.title AS episode_title FROM social_post_queue q
     LEFT JOIN episodes e ON e.id = q.episode_id
     ORDER BY q.scheduled_for ASC'
)->fetchAll() : [];

$episodes  = $pdo ? $pdo->query('SELECT id, title FROM episodes ORDER BY title')->fetchAll() : [];
$platforms = ['YouTube','YouTube Shorts','TikTok','Instagram Reels','Facebook Reels','X'];
$statuses  = ['draft','queued','scheduled','posted','failed','canceled'];

$pageActions = '<a href="/admin/social-schedule.php" class="btn btn-ptmd-outline">
    <i class="fa-solid fa-clock me-2"></i>Manage Schedule
</a>';
?>

<!-- Add to queue form -->
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-calendar-plus me-2 ptmd-text-teal"></i>Add to Queue
    </h2>
    <form method="post" action="/admin/posts.php">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action" value="add">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Episode (optional)</label>
                <select class="form-select" name="episode_id">
                    <option value="">— None (manual post) —</option>
                    <?php foreach ($episodes as $ep): ?>
                        <option value="<?php ee((string) $ep['id']); ?>"><?php ee($ep['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Platform</label>
                <select class="form-select" name="platform" required>
                    <?php foreach ($platforms as $p): ?>
                        <option><?php ee($p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Content Type</label>
                <input class="form-control" name="content_type" placeholder="teaser, clip…">
            </div>
            <div class="col-md-2">
                <label class="form-label">Scheduled For</label>
                <input class="form-control" type="datetime-local" name="scheduled_for" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Asset Path (optional)</label>
                <input class="form-control" name="asset_path" placeholder="/uploads/clips/…">
            </div>
            <div class="col-12">
                <label class="form-label">Caption</label>
                <textarea class="form-control" name="caption" rows="2"
                    placeholder="Social caption, hashtags, emojis…"></textarea>
            </div>
            <div class="col-12">
                <button class="btn btn-ptmd-primary" type="submit">
                    <i class="fa-solid fa-calendar-plus me-2"></i>Add to Queue
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Queue table -->
<div class="ptmd-panel p-lg">
    <h2 class="h6 mb-4">Queue</h2>
    <?php if ($queue): ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr><th>Platform</th><th>Episode</th><th>Scheduled</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($queue as $item): ?>
                        <tr>
                            <td class="fw-500"><?php ee($item['platform']); ?></td>
                            <td class="ptmd-muted small"><?php ee($item['episode_title'] ?? '—'); ?></td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php echo $item['scheduled_for'] ? e(date('M j, Y g:ia', strtotime($item['scheduled_for']))) : '—'; ?>
                            </td>
                            <td>
                                <form method="post" action="/admin/posts.php" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                    <input type="hidden" name="_action" value="update_status">
                                    <input type="hidden" name="id" value="<?php ee((string) $item['id']); ?>">
                                    <select class="form-select form-select-sm"
                                        name="status"
                                        style="width:auto;display:inline-block"
                                        data-auto-submit>
                                        <?php foreach ($statuses as $s): ?>
                                            <option value="<?php ee($s); ?>"
                                                <?php echo $item['status'] === $s ? 'selected' : ''; ?>>
                                                <?php ee(ucfirst($s)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <form method="post" action="/admin/posts.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                        <input type="hidden" name="_action" value="publish_now">
                                        <input type="hidden" name="id" value="<?php ee((string) $item['id']); ?>">
                                        <button class="btn btn-ptmd-ghost btn-sm" type="submit" data-tippy-content="Publish now (stub)">
                                            <i class="fa-solid fa-rocket"></i>
                                        </button>
                                    </form>
                                    <form method="post" action="/admin/posts.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="id" value="<?php ee((string) $item['id']); ?>">
                                        <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                            style="color:var(--ptmd-error)"
                                            data-confirm="Delete this queue item?"
                                            data-tippy-content="Delete">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="ptmd-muted small">Queue is empty.</p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_admin_footer.php'; ?>

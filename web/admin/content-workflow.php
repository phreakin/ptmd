<?php
/**
 * PTMD Admin — Content Workflow
 *
 * Topic -> case draft -> asset assignment -> social queue automation.
 */

$pageTitle    = 'Content Workflow | PTMD Admin';
$activePage   = 'content-workflow';
$pageHeading  = 'Content Workflow Automation';
$pageSubheading = 'Run end-to-end topic-to-posting workflows and track their status.';

include __DIR__ . '/_admin_head.php';
require_once __DIR__ . '/../inc/content_workflow.php';

$pdo = get_db();

if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/content-workflow.php', 'Invalid CSRF token.', 'danger');
    }

    $postAction = trim((string) ($_POST['_action'] ?? 'run_workflow'));

    if ($postAction === 'run_worker') {
        $limit = (int) ($_POST['limit'] ?? 25);
        $result = ptmd_process_due_social_queue($pdo, $limit);
        if (!empty($result['ok'])) {
            $msg = 'Worker processed ' . (int) $result['processed'] . ' due posts (' . (int) $result['posted'] . ' posted, ' . (int) $result['failed'] . ' failed).';
            redirect('/admin/content-workflow.php', $msg, 'success');
        }
        redirect('/admin/content-workflow.php', 'Worker failed: ' . ($result['error'] ?? 'Unknown error'), 'danger');
    }

    if ($postAction === 'run_workflow') {
        $workflowResult = ptmd_run_content_workflow([
            'topic'         => trim((string) ($_POST['topic'] ?? '')),
            'case_id'       => (int) ($_POST['case_id'] ?? 0),
            'clip_id'       => (int) ($_POST['clip_id'] ?? 0),
            'asset_path'    => trim((string) ($_POST['asset_path'] ?? '')),
            'content_type'  => trim((string) ($_POST['content_type'] ?? '')),
            'caption'       => trim((string) ($_POST['caption'] ?? '')),
            'auto_dispatch' => !empty($_POST['auto_dispatch']),
            'created_by'    => (int) ($_SESSION['admin_user_id'] ?? 0),
        ]);

        if (!empty($workflowResult['ok'])) {
            $msg = 'Workflow #' . (int) $workflowResult['workflow_id']
                . ' created with ' . (int) $workflowResult['queue_count'] . ' queue items.';
            if (!empty($workflowResult['dispatch']) && !empty($workflowResult['dispatch']['ok'])) {
                $msg .= ' Dispatch: '
                    . (int) $workflowResult['dispatch']['processed'] . ' processed, '
                    . (int) $workflowResult['dispatch']['posted'] . ' posted, '
                    . (int) $workflowResult['dispatch']['failed'] . ' failed.';
            }
            redirect('/admin/content-workflow.php', $msg, 'success');
        }

        redirect('/admin/content-workflow.php', 'Workflow failed: ' . ($workflowResult['error'] ?? 'Unknown error'), 'danger');
    }
}

$cases = $pdo
    ? $pdo->query('SELECT id, title FROM cases ORDER BY updated_at DESC LIMIT 200')->fetchAll()
    : [];
$clips = $pdo
    ? $pdo->query('SELECT id, label, output_path, source_path FROM video_clips ORDER BY created_at DESC LIMIT 200')->fetchAll()
    : [];

$workflows = $pdo
    ? $pdo->query(
        'SELECT
            w.*,
            c.title AS case_title,
            vc.label AS clip_label,
            u.username AS created_by_username,
            COUNT(DISTINCT wp.id) AS post_count,
            SUM(CASE WHEN wp.status = "posted" THEN 1 ELSE 0 END) AS posted_count,
            SUM(CASE WHEN wp.status = "failed" THEN 1 ELSE 0 END) AS failed_count,
            SUM(CASE WHEN wp.status = "queued" THEN 1 ELSE 0 END) AS queued_count
         FROM content_workflows w
         LEFT JOIN cases c ON c.id = w.case_id
         LEFT JOIN video_clips vc ON vc.id = w.source_clip_id
         LEFT JOIN users u ON u.id = w.created_by
         LEFT JOIN content_workflow_posts wp ON wp.workflow_id = w.id
         GROUP BY w.id
         ORDER BY w.created_at DESC
         LIMIT 50'
    )->fetchAll()
    : [];
?>

<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-gears me-2 ptmd-text-teal"></i>Create Workflow
    </h2>
    <form method="post" action="/admin/content-workflow.php">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action" value="run_workflow">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Topic</label>
                <input class="form-control" name="topic" required placeholder="e.g. Housing affordability and permit bottlenecks">
            </div>
            <div class="col-md-3">
                <label class="form-label">Existing case (optional)</label>
                <select class="form-select" name="case_id">
                    <option value="">— Auto-create draft case —</option>
                    <?php foreach ($cases as $ep): ?>
                        <option value="<?php ee((string) $ep['id']); ?>"><?php ee($ep['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Video clip (optional)</label>
                <select class="form-select" name="clip_id">
                    <option value="">— No clip —</option>
                    <?php foreach ($clips as $clip): ?>
                        <option value="<?php ee((string) $clip['id']); ?>"><?php ee($clip['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Asset path override (optional)</label>
                <input class="form-control" name="asset_path" placeholder="clips/my-video.mp4">
            </div>
            <div class="col-md-4">
                <label class="form-label">Content type override (optional)</label>
                <input class="form-control" name="content_type" placeholder="teaser, clip, full documentary">
            </div>
            <div class="col-md-4">
                <label class="form-label">Caption override (optional)</label>
                <input class="form-control" name="caption" placeholder="Leave blank to use per-site defaults">
            </div>
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="auto_dispatch" name="auto_dispatch" value="1">
                    <label class="form-check-label" for="auto_dispatch">
                        Auto-dispatch due posts immediately after queue creation
                    </label>
                </div>
            </div>
            <div class="col-12">
                <button class="btn btn-ptmd-primary" type="submit">
                    <i class="fa-solid fa-play me-2"></i>Run Workflow
                </button>
            </div>
        </div>
    </form>
</div>

<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-rocket me-2 ptmd-text-teal"></i>Run Posting Worker
    </h2>
    <form method="post" action="/admin/content-workflow.php" class="row g-3 align-items-end">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action" value="run_worker">
        <div class="col-md-3">
            <label class="form-label">Max queue items</label>
            <input type="number" class="form-control" name="limit" value="25" min="1" max="100">
        </div>
        <div class="col-md-9">
            <button class="btn btn-ptmd-outline" type="submit">
                <i class="fa-solid fa-bolt me-2"></i>Dispatch Due Posts Now
            </button>
        </div>
    </form>
    <div class="ptmd-muted small mt-3">
        For automated cron usage, call <code>/api/social_dispatch_worker.php?token=YOUR_TOKEN</code>
        with a token stored in <code>site_settings.automation_worker_token</code>.
    </div>
</div>

<div class="ptmd-panel p-lg">
    <h2 class="h6 mb-4">Recent Workflow Runs</h2>
    <?php if ($workflows): ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Topic</th>
                        <th>Case</th>
                        <th>Clip</th>
                        <th>Status</th>
                        <th>Posts</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($workflows as $w): ?>
                        <tr>
                            <td>#<?php ee((string) $w['id']); ?></td>
                            <td class="fw-500"><?php ee($w['topic']); ?></td>
                            <td><?php ee($w['case_title'] ?? '—'); ?></td>
                            <td><?php ee($w['clip_label'] ?? '—'); ?></td>
                            <td><span class="ptmd-status ptmd-status-<?php ee($w['status']); ?>"><?php ee($w['status']); ?></span></td>
                            <td class="ptmd-muted small">
                                <?php ee((string) ($w['post_count'] ?? 0)); ?> total ·
                                <?php ee((string) ($w['posted_count'] ?? 0)); ?> posted ·
                                <?php ee((string) ($w['queued_count'] ?? 0)); ?> queued ·
                                <?php ee((string) ($w['failed_count'] ?? 0)); ?> failed
                            </td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php echo e(date('M j, Y g:ia', strtotime($w['created_at']))); ?>
                                <?php if (!empty($w['created_by_username'])): ?>
                                    <br><span class="ptmd-muted">by <?php ee($w['created_by_username']); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="ptmd-muted small mb-0">No workflow runs yet.</p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_admin_footer.php'; ?>

<?php
/**
 * PTMD Admin — Edit Jobs
 *
 * Lists all edit jobs, lets admins create new jobs, view per-output status,
 * retry failed outputs, cancel jobs, and trigger the worker.
 */

$pageTitle      = 'Edit Jobs | PTMD Admin';
$activePage     = 'edit-jobs';
$pageHeading    = 'Edit Jobs';
$pageSubheading = 'Automate the full pipeline: source video → overlays + captions → platform exports → social queue.';

include __DIR__ . '/_admin_head.php';

require_once __DIR__ . '/../inc/video_processor.php';

$pdo = get_db();

// ── Handle POST actions (retry, cancel, run-worker) ───────────────────────────
if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect(route_admin('edit-jobs'), 'Invalid CSRF token.', 'danger');
    }

    $postAction = $_POST['_action'] ?? '';

    if ($postAction === 'run_worker') {
        $summary = run_edit_job_worker($pdo, 0, 100);
        redirect(
            route_admin('edit-jobs'),
            sprintf(
                'Worker finished: %d outputs processed (%d failed) across %d job(s).',
                $summary['processed'],
                $summary['failed'],
                $summary['jobs']
            ),
            $summary['failed'] > 0 ? 'warning' : 'success'
        );
    }

    if ($postAction === 'cancel') {
        $jobId = (int) ($_POST['job_id'] ?? 0);
        if ($jobId > 0) {
            $pdo->prepare(
                'UPDATE edit_jobs SET status = "canceled", updated_at = NOW()
                 WHERE id = :id AND status IN ("pending","processing")'
            )->execute(['id' => $jobId]);
            $pdo->prepare(
                'UPDATE edit_job_outputs SET status = "failed", error_message = "Job canceled", updated_at = NOW()
                 WHERE job_id = :jid AND status = "pending"'
            )->execute(['jid' => $jobId]);
        }
        redirect(route_admin('edit-jobs'), 'Job canceled.', 'info');
    }

    if ($postAction === 'retry_output') {
        $outputId = (int) ($_POST['output_id'] ?? 0);
        if ($outputId > 0) {
            $pdo->prepare(
                'UPDATE edit_job_outputs
                 SET status = "pending", error_message = NULL, ffmpeg_command = NULL,
                     retry_count = retry_count + 1, updated_at = NOW()
                 WHERE id = :id AND status = "failed"'
            )->execute(['id' => $outputId]);
            // Re-open the parent job
            $getJob = $pdo->prepare('SELECT job_id FROM edit_job_outputs WHERE id = :id');
            $getJob->execute(['id' => $outputId]);
            $jid = (int) $getJob->fetchColumn();
            if ($jid > 0) {
                $pdo->prepare(
                    'UPDATE edit_jobs SET status = "pending", updated_at = NOW()
                     WHERE id = :id AND status IN ("failed","completed","canceled")'
                )->execute(['id' => $jid]);
            }
        }
        redirect(route_admin('edit-jobs'), 'Output queued for retry.', 'success');
    }
}

// ── Load data ─────────────────────────────────────────────────────────────────

// Recent jobs with counts
$jobs = [];
if ($pdo) {
    $jobs = $pdo->query(
        'SELECT ej.id, ej.label, ej.status, ej.source_path, ej.caption_mode,
                ej.platforms_json, ej.created_at, ej.updated_at,
                u.username AS created_by_name,
                COUNT(ejo.id)             AS total_outputs,
                SUM(ejo.status = "done")  AS done_outputs,
                SUM(ejo.status = "failed") AS failed_outputs,
                SUM(ejo.status = "pending" OR ejo.status = "processing") AS pending_outputs
         FROM edit_jobs ej
         LEFT JOIN users u ON u.id = ej.created_by
         LEFT JOIN edit_job_outputs ejo ON ejo.job_id = ej.id
         GROUP BY ej.id
         ORDER BY ej.created_at DESC
         LIMIT 50'
    )->fetchAll();
}

// Queue depth indicator
$pendingCount = 0;
if ($pdo) {
    $pendingCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM edit_job_outputs WHERE status IN ("pending","processing")'
    )->fetchColumn();
}

// Source clips for create form
$clips = [];
if ($pdo) {
    $clips = $pdo->query(
        'SELECT vc.id, vc.label, vc.source_path, vc.output_path, vc.status, e.title AS episode_title
         FROM video_clips vc
         LEFT JOIN episodes e ON e.id = vc.episode_id
         ORDER BY vc.created_at DESC LIMIT 100'
    )->fetchAll();
}

// Available overlays
$brandOverlayDir = $_SERVER['DOCUMENT_ROOT'] . '/assets/brand/overlays';
$brandOverlays   = [];
if (is_dir($brandOverlayDir)) {
    foreach (glob($brandOverlayDir . '/*.{png,gif,webp}', GLOB_BRACE) as $f) {
        $brandOverlays[] = [
            'path'  => '/assets/brand/overlays/' . basename($f),
            'label' => basename($f, '.' . pathinfo($f, PATHINFO_EXTENSION)),
        ];
    }
}
$dbOverlays = [];
if ($pdo) {
    $dbOverlays = $pdo->query(
        'SELECT id, filename, file_path FROM media_library WHERE category IN ("overlay","watermark","logo") ORDER BY created_at DESC LIMIT 50'
    )->fetchAll();
}

// Detail view for a single job
$viewJobId = isset($_GET['view_job']) ? (int) $_GET['view_job'] : 0;
$jobDetail = null;
$jobOutputs = [];
if ($viewJobId > 0 && $pdo) {
    $stmt = $pdo->prepare('SELECT ej.*, u.username AS created_by_name FROM edit_jobs ej LEFT JOIN users u ON u.id = ej.created_by WHERE ej.id = :id');
    $stmt->execute(['id' => $viewJobId]);
    $jobDetail = $stmt->fetch();
    if ($jobDetail) {
        $outStmt = $pdo->prepare('SELECT * FROM edit_job_outputs WHERE job_id = :jid ORDER BY id');
        $outStmt->execute(['jid' => $viewJobId]);
        $jobOutputs = $outStmt->fetchAll();
    }
}

$PLATFORMS = ['YouTube', 'YouTube Shorts', 'TikTok', 'Instagram Reels', 'Facebook Reels', 'X'];
?>

<!-- Queue depth banner -->
<?php if ($pendingCount > 0): ?>
<div class="alert ptmd-alert alert-warning mb-4" role="alert">
    <i class="fa-solid fa-hourglass-half me-2"></i>
    <strong><?php ee((string) $pendingCount); ?> output(s)</strong> pending in the queue.
    <form method="post" action="<?php echo e(route_admin('edit-jobs')); ?>" class="d-inline ms-3">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action" value="run_worker">
        <button class="btn btn-ptmd-outline btn-sm" type="submit">
            <i class="fa-solid fa-play me-1"></i>Run Worker Now
        </button>
    </form>
</div>
<?php endif; ?>

<!-- Create New Edit Job -->
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-plus-circle me-2 ptmd-text-teal"></i>Create New Edit Job
    </h2>
    <form method="post" action="<?php echo e(route_admin('edit-jobs')); ?>" id="editJobForm">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action" value="create_job">

        <div class="row g-3 mb-3">
            <!-- Label -->
            <div class="col-md-4">
                <label class="form-label" for="ej_label">Job Label</label>
                <input class="form-control" id="ej_label" name="label"
                    placeholder="e.g. Episode 2 — Teaser Exports" required>
            </div>

            <!-- Source clip -->
            <div class="col-md-4">
                <label class="form-label" for="ej_clip">Source Clip</label>
                <select class="form-select" id="ej_clip" name="source_clip_id"
                    onchange="document.getElementById('ej_path').value=this.options[this.selectedIndex].dataset.path||''">
                    <option value="">— Select clip —</option>
                    <?php foreach ($clips as $clip): ?>
                        <option
                            value="<?php ee((string) $clip['id']); ?>"
                            data-path="/uploads/<?php ee($clip['output_path'] ?: $clip['source_path']); ?>"
                        >
                            <?php ee($clip['label']); ?>
                            <?php if ($clip['episode_title']): ?> — <?php ee($clip['episode_title']); ?><?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Manual source path fallback -->
            <div class="col-md-4">
                <label class="form-label" for="ej_path">Source Path (or enter manually)</label>
                <input class="form-control" id="ej_path" name="source_path"
                    placeholder="/uploads/clips/…" required>
                <div class="form-text ptmd-muted" style="font-size:var(--text-xs)">
                    Auto-filled when you pick a clip above.
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <!-- Caption mode -->
            <div class="col-md-3">
                <label class="form-label" for="ej_caption">Caption Mode</label>
                <select class="form-select" id="ej_caption" name="caption_mode">
                    <option value="none">None</option>
                    <option value="embedded">Embedded (burned in)</option>
                    <option value="sidecar">Sidecar (SRT/VTT)</option>
                </select>
            </div>

            <!-- Primary overlay -->
            <div class="col-md-5">
                <label class="form-label" for="ej_overlay">Primary Overlay (optional)</label>
                <select class="form-select" id="ej_overlay" name="overlay_path">
                    <option value="">— None —</option>
                    <?php foreach ($brandOverlays as $ov): ?>
                        <option value="<?php ee($ov['path']); ?>"><?php ee($ov['label']); ?></option>
                    <?php endforeach; ?>
                    <?php foreach ($dbOverlays as $ov): ?>
                        <option value="/uploads/<?php ee($ov['file_path']); ?>"><?php ee($ov['filename']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Max retries -->
            <div class="col-md-2">
                <label class="form-label" for="ej_retries">Max Retries</label>
                <input class="form-control" id="ej_retries" name="max_retries"
                    type="number" min="0" max="10" value="3">
            </div>
        </div>

        <!-- Platform targets -->
        <div class="mb-3">
            <label class="form-label">Target Platforms</label>
            <div class="d-flex flex-wrap gap-3">
                <?php foreach ($PLATFORMS as $platform): ?>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox"
                            name="platforms[]"
                            value="<?php ee($platform); ?>"
                            id="plat_<?php ee(preg_replace('/\W/', '_', $platform)); ?>">
                        <label class="form-check-label" for="plat_<?php ee(preg_replace('/\W/', '_', $platform)); ?>">
                            <?php ee($platform); ?>
                        </label>
                    </div>
                <?php endforeach; ?>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox"
                        name="platforms[]" value="generic" id="plat_generic" checked>
                    <label class="form-check-label" for="plat_generic">Generic (no platform)</label>
                </div>
            </div>
        </div>

        <button class="btn btn-ptmd-primary" type="submit">
            <i class="fa-solid fa-plus me-2"></i>Create Edit Job
        </button>
        <span class="ptmd-muted small ms-3">
            Each selected platform creates one output render.
        </span>
    </form>
</div>

<?php
// Handle create_job POST (HTML form → redirect)
if ($pdo && is_post() && ($_POST['_action'] ?? '') === 'create_job') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect(route_admin('edit-jobs'), 'Invalid CSRF token.', 'danger');
    }

    $label       = trim((string) ($_POST['label']          ?? 'Untitled Edit Job'));
    $sourcePath  = trim((string) ($_POST['source_path']    ?? ''));
    $captionMode = trim((string) ($_POST['caption_mode']   ?? 'none'));
    $clipId      = (int) ($_POST['source_clip_id'] ?? 0) ?: null;
    $overlayPath = trim((string) ($_POST['overlay_path']   ?? ''));
    $maxRetries  = max(0, min(10, (int) ($_POST['max_retries'] ?? 3)));
    $platforms   = $_POST['platforms'] ?? ['generic'];

    if ($sourcePath === '' || !vp_is_safe_path($sourcePath)) {
        redirect(route_admin('edit-jobs'), 'Invalid or missing source path.', 'danger');
    }
    if (!in_array($captionMode, ['none','embedded','sidecar'], true)) {
        $captionMode = 'none';
    }

    $allowedPlatforms = ['YouTube','YouTube Shorts','TikTok','Instagram Reels','Facebook Reels','X','generic'];
    $platforms = array_values(array_filter(
        (array) $platforms,
        fn($p) => in_array(trim((string) $p), $allowedPlatforms, true)
    ));
    if (empty($platforms)) {
        $platforms = ['generic'];
    }

    // Strip /uploads/ prefix to store relative path
    $relPath = ltrim(str_replace('/uploads/', '', $sourcePath), '/');

    $jobStmt = $pdo->prepare(
        'INSERT INTO edit_jobs
         (label, source_clip_id, source_path, caption_mode, platforms_json, status,
          max_retries, created_by, created_at, updated_at)
         VALUES (:label, :clip, :src, :cm, :plat, "pending", :maxr, :user, NOW(), NOW())'
    );
    $jobStmt->execute([
        'label' => $label,
        'clip'  => $clipId,
        'src'   => $relPath,
        'cm'    => $captionMode,
        'plat'  => json_encode($platforms),
        'maxr'  => $maxRetries,
        'user'  => (int) ($_SESSION['admin_user_id'] ?? 0),
    ]);
    $jobId = (int) $pdo->lastInsertId();

    // Create one output per platform
    $outStmt = $pdo->prepare(
        'INSERT INTO edit_job_outputs
         (job_id, platform, caption_mode, overlay_path, status, created_at, updated_at)
         VALUES (:jid, :platform, :cm, :overlay, "pending", NOW(), NOW())'
    );
    foreach ($platforms as $platform) {
        $outStmt->execute([
            'jid'     => $jobId,
            'platform'=> $platform,
            'cm'      => $captionMode,
            'overlay' => $overlayPath ?: null,
        ]);
    }

    redirect(route_admin('edit-jobs', ['view_job' => $jobId]), 'Edit job created.', 'success');
}
?>

<!-- Job detail view -->
<?php if ($jobDetail): ?>
<div class="ptmd-panel p-lg mb-4">
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h2 class="h5 mb-1">
                Job #<?php ee((string) $jobDetail['id']); ?> — <?php ee($jobDetail['label']); ?>
            </h2>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <span class="ptmd-status ptmd-status-<?php ee($jobDetail['status']); ?>">
                    <?php ee($jobDetail['status']); ?>
                </span>
                <span class="ptmd-muted small">
                    Caption: <strong><?php ee($jobDetail['caption_mode']); ?></strong>
                </span>
                <span class="ptmd-muted small">
                    Source: <code><?php ee($jobDetail['source_path']); ?></code>
                </span>
                <?php if ($jobDetail['created_by_name']): ?>
                    <span class="ptmd-muted small">By <?php ee($jobDetail['created_by_name']); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex gap-2">
            <?php if (in_array($jobDetail['status'], ['pending','processing'], true)): ?>
                <form method="post" action="<?php echo e(route_admin('edit-jobs')); ?>">
                    <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                    <input type="hidden" name="_action" value="cancel">
                    <input type="hidden" name="job_id" value="<?php ee((string) $jobDetail['id']); ?>">
                    <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                        onclick="return confirm('Cancel this job?')">
                        <i class="fa-solid fa-ban me-1"></i>Cancel
                    </button>
                </form>
            <?php endif; ?>
            <a href="<?php ee(route_admin('edit-jobs')); ?>" class="btn btn-ptmd-outline btn-sm">
                <i class="fa-solid fa-arrow-left me-1"></i>Back to Jobs
            </a>
        </div>
    </div>

    <?php if ($jobOutputs): ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Platform</th>
                        <th>Caption</th>
                        <th>Overlay</th>
                        <th>Output</th>
                        <th>Status</th>
                        <th>Retries</th>
                        <th>Error</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobOutputs as $out): ?>
                        <tr>
                            <td class="ptmd-muted">#<?php ee((string) $out['id']); ?></td>
                            <td>
                                <span class="ptmd-badge-muted"><?php ee($out['platform']); ?></span>
                            </td>
                            <td class="ptmd-muted small"><?php ee($out['caption_mode']); ?></td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php echo $out['overlay_path'] ? e(basename((string) $out['overlay_path'])) : '—'; ?>
                            </td>
                            <td style="font-size:var(--text-xs)">
                                <?php if ($out['output_path']): ?>
                                    <a href="/uploads/<?php ee($out['output_path']); ?>" target="_blank" rel="noopener">
                                        <?php echo e(basename((string) $out['output_path'])); ?>
                                    </a>
                                    <?php if ($out['queue_item_id']): ?>
                                        <a href="<?php ee(route_admin('posts')); ?>" class="ptmd-muted ms-1"
                                           data-tippy-content="In social queue">
                                            <i class="fa-solid fa-calendar-check"></i>
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="ptmd-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="ptmd-status ptmd-status-<?php ee($out['status']); ?>">
                                    <?php ee($out['status']); ?>
                                </span>
                            </td>
                            <td class="ptmd-muted small"><?php ee((string) $out['retry_count']); ?></td>
                            <td style="font-size:var(--text-xs);color:var(--ptmd-error);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                <?php if ($out['error_message']): ?>
                                    <span data-tippy-content="<?php ee($out['error_message']); ?>">
                                        <?php ee(mb_substr((string) $out['error_message'], 0, 80)); ?>
                                    </span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <?php if ($out['status'] === 'failed'): ?>
                                        <form method="post" action="<?php echo e(route_admin('edit-jobs')); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                            <input type="hidden" name="_action" value="retry_output">
                                            <input type="hidden" name="output_id" value="<?php ee((string) $out['id']); ?>">
                                            <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                                data-tippy-content="Retry this output">
                                                <i class="fa-solid fa-rotate-right"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($out['ffmpeg_command']): ?>
                                        <button
                                            class="btn btn-ptmd-ghost btn-sm"
                                            type="button"
                                            onclick="navigator.clipboard?.writeText(this.dataset.cmd); this.textContent='Copied'"
                                            data-cmd="<?php ee($out['ffmpeg_command']); ?>"
                                            data-tippy-content="Copy FFmpeg command"
                                        >
                                            <i class="fa-solid fa-terminal"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="ptmd-muted small">No outputs for this job yet.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Job list -->
<div class="ptmd-panel p-lg mb-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h6 mb-0">
            <i class="fa-solid fa-list-check me-2 ptmd-text-teal"></i>All Edit Jobs
        </h2>
        <?php if (!$viewJobId): ?>
            <form method="post" action="<?php echo e(route_admin('edit-jobs')); ?>">
                <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                <input type="hidden" name="_action" value="run_worker">
                <button class="btn btn-ptmd-outline btn-sm" type="submit">
                    <i class="fa-solid fa-play me-1"></i>Run Worker
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($jobs): ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Label</th>
                        <th>Source</th>
                        <th>Caption</th>
                        <th>Platforms</th>
                        <th>Outputs</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $job): ?>
                        <tr>
                            <td class="ptmd-muted">#<?php ee((string) $job['id']); ?></td>
                            <td class="fw-500 ptmd-text-muted">
                                <a href="<?php ee(route_admin('edit-jobs', ['view_job' => (string) $job['id']])); ?>">
                                    <?php ee($job['label']); ?>
                                </a>
                            </td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php echo e(basename((string) $job['source_path'])); ?>
                            </td>
                            <td>
                                <span class="ptmd-badge-muted"><?php ee($job['caption_mode']); ?></span>
                            </td>
                            <td style="font-size:var(--text-xs)">
                                <?php
                                $plats = json_decode((string) ($job['platforms_json'] ?? '[]'), true);
                                if (is_array($plats)) {
                                    echo e(implode(', ', $plats));
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                $done   = (int) $job['done_outputs'];
                                $total  = (int) $job['total_outputs'];
                                $failed = (int) $job['failed_outputs'];
                                echo e($done) . ' / ' . e($total);
                                if ($failed > 0) {
                                    echo ' <span style="color:var(--ptmd-error)">(' . e($failed) . ' failed)</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <span class="ptmd-status ptmd-status-<?php ee($job['status']); ?>">
                                    <?php ee($job['status']); ?>
                                </span>
                            </td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php echo e(date('M j, Y g:ia', strtotime($job['created_at']))); ?>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a
                                        href="<?php ee(route_admin('edit-jobs', ['view_job' => (string) $job['id']])); ?>"
                                        class="btn btn-ptmd-ghost btn-sm"
                                        data-tippy-content="View details"
                                    >
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                    <?php if (in_array($job['status'], ['pending','processing'], true)): ?>
                                        <form method="post" action="<?php echo e(route_admin('edit-jobs')); ?>" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                            <input type="hidden" name="_action" value="cancel">
                                            <input type="hidden" name="job_id" value="<?php ee((string) $job['id']); ?>">
                                            <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                                data-tippy-content="Cancel job"
                                                onclick="return confirm('Cancel job #<?php ee((string) $job['id']); ?>?')">
                                                <i class="fa-solid fa-ban"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="ptmd-muted small">No edit jobs yet. Use the form above to create your first automated edit job.</p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_admin_footer.php'; ?>

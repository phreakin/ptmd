<?php
/**
 * PTMD Admin — Video Pipeline
 *
 * Monitor and trigger the automated post-production pipeline.
 * Each pipeline job takes a completed clip through:
 *   1. Brand Imaging    — composite brand overlay
 *   2. Clip Generation  — create per-platform resized clips
 *   3. Queueing         — create social_post_queue entries
 */

$pageTitle      = 'Pipeline | PTMD Admin';
$activePage     = 'pipeline';
$pageHeading    = 'Video Pipeline';
$pageSubheading = 'Automate brand imaging, platform clips, and social queueing for completed videos.';

include __DIR__ . '/_admin_head.php';

require_once __DIR__ . '/../inc/pipeline.php';

$pdo = get_db();

// ── Handle cancel action ──────────────────────────────────────────────────────

if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/pipeline.php', 'Invalid CSRF token.', 'danger');
    }

    $postAction = $_POST['_action'] ?? '';

    if ($postAction === 'cancel') {
        $cancelId = (int) ($_POST['job_id'] ?? 0);
        if ($cancelId > 0) {
            $pdo->prepare(
                'UPDATE pipeline_jobs SET status = "canceled", updated_at = NOW()
                 WHERE id = :id AND status NOT IN ("completed","canceled")'
            )->execute(['id' => $cancelId]);
            redirect('/admin/pipeline.php', 'Job canceled.', 'success');
        }
    }
}

// ── Load data ─────────────────────────────────────────────────────────────────

// Ready/complete clips eligible to start a new pipeline job
$eligibleClips = $pdo ? $pdo->query(
    'SELECT vc.id, vc.label, vc.status, vc.duration_sec, e.title AS episode_title
     FROM video_clips vc
     LEFT JOIN episodes e ON e.id = vc.episode_id
     WHERE vc.status IN ("raw","ready","complete")
       AND vc.id NOT IN (
           SELECT source_clip_id FROM pipeline_jobs
            WHERE source_clip_id IS NOT NULL
              AND status NOT IN ("failed","canceled")
       )
     ORDER BY vc.created_at DESC
     LIMIT 100'
)->fetchAll() : [];

// Available overlays from brand directory + media library
$brandOverlayDir = $_SERVER['DOCUMENT_ROOT'] . '/assets/brand/overlays';
$overlayOptions  = [];
if (is_dir($brandOverlayDir)) {
    foreach (glob($brandOverlayDir . '/*.{png,gif,webp}', GLOB_BRACE) as $f) {
        $overlayOptions[] = [
            'path'  => '/assets/brand/overlays/' . basename($f),
            'label' => basename($f, '.' . pathinfo($f, PATHINFO_EXTENSION)),
        ];
    }
}
if ($pdo) {
    $dbOv = $pdo->query(
        'SELECT filename, file_path FROM media_library WHERE category = "overlay" ORDER BY created_at DESC'
    )->fetchAll();
    foreach ($dbOv as $ov) {
        $overlayOptions[] = [
            'path'  => '/uploads/' . $ov['file_path'],
            'label' => $ov['filename'],
        ];
    }
}

// Recent pipeline jobs
$jobs = $pdo ? $pdo->query(
    'SELECT j.*,
            u.username AS created_by_name,
            e.title    AS episode_title,
            sc.label   AS source_clip_label,
            (SELECT COUNT(*) FROM pipeline_items pi WHERE pi.pipeline_job_id = j.id)                      AS item_total,
            (SELECT COUNT(*) FROM pipeline_items pi WHERE pi.pipeline_job_id = j.id AND pi.status = "done")    AS item_done,
            (SELECT COUNT(*) FROM pipeline_items pi WHERE pi.pipeline_job_id = j.id AND pi.status = "failed")  AS item_failed
     FROM pipeline_jobs j
     LEFT JOIN users      u  ON u.id  = j.created_by
     LEFT JOIN episodes   e  ON e.id  = j.episode_id
     LEFT JOIN video_clips sc ON sc.id = j.source_clip_id
     ORDER BY j.created_at DESC
     LIMIT 50'
)->fetchAll() : [];

$platforms    = get_platform_profiles();
$defaultPlatJson = site_setting('pipeline_default_platforms', '["youtube_shorts","tiktok","instagram_reels","facebook_reels","x"]');
$defaultPlats = json_decode($defaultPlatJson, true) ?: [];

$defaultPreset = [
    'overlay_path' => site_setting('pipeline_brand_overlay',    ''),
    'position'     => site_setting('pipeline_overlay_position', 'bottom-right'),
    'opacity'      => site_setting('pipeline_overlay_opacity',  '1.00'),
    'scale'        => site_setting('pipeline_overlay_scale',    '30'),
];
$defaultOffset  = (int) site_setting('pipeline_schedule_offset_hrs', '24');
$defaultAutoQ   = (int) site_setting('pipeline_auto_queue', '1');
$cronToken      = site_setting('cron_token', '');

$pageActions = '<a href="/admin/video-processor.php" class="btn btn-ptmd-outline">
    <i class="fa-solid fa-scissors me-2"></i>Video Processor
</a>';
?>

<!-- ── Start new pipeline job ────────────────────────────────────────────── -->
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-rocket me-2 ptmd-text-teal"></i>Start Pipeline Job
    </h2>

    <?php if (!$eligibleClips): ?>
        <p class="ptmd-muted small mb-0">
            No ready clips found.
            <a href="/admin/video-processor.php">Upload or extract a clip</a> first,
            then return here to run the pipeline.
        </p>
    <?php else: ?>
    <form id="pipelineForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">

        <div class="row g-3 mb-4">
            <!-- Source clip -->
            <div class="col-md-5">
                <label class="form-label">Source Clip <span class="ptmd-text-teal">*</span></label>
                <select class="form-select" name="clip_id" required>
                    <option value="">— Select clip —</option>
                    <?php foreach ($eligibleClips as $c): ?>
                        <option value="<?php ee((string) $c['id']); ?>"
                            <?php if (isset($_GET['clip_id']) && (int)$_GET['clip_id'] === (int)$c['id']) echo 'selected'; ?>>
                            <?php ee($c['label']); ?>
                            <?php if ($c['episode_title']): ?> — <?php ee($c['episode_title']); ?><?php endif; ?>
                            [<?php ee($c['status']); ?>]
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Overlay -->
            <div class="col-md-4">
                <label class="form-label">Brand Overlay</label>
                <select class="form-select" name="overlay_path">
                    <option value="">— Use site default —</option>
                    <?php foreach ($overlayOptions as $ov): ?>
                        <option value="<?php ee($ov['path']); ?>"
                            <?php echo $defaultPreset['overlay_path'] === $ov['path'] ? 'selected' : ''; ?>>
                            <?php ee($ov['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Position -->
            <div class="col-md-3">
                <label class="form-label">Position</label>
                <select class="form-select" name="position">
                    <?php foreach (['top-left','top-right','center','bottom-left','bottom-right','full'] as $pos): ?>
                        <option value="<?php ee($pos); ?>"
                            <?php echo $defaultPreset['position'] === $pos ? 'selected' : ''; ?>>
                            <?php ee($pos); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Opacity -->
            <div class="col-md-2">
                <label class="form-label">Opacity</label>
                <input class="form-control" type="number" name="opacity" min="0" max="1" step="0.05"
                    value="<?php ee($defaultPreset['opacity']); ?>">
            </div>

            <!-- Scale -->
            <div class="col-md-2">
                <label class="form-label">Overlay Scale %</label>
                <input class="form-control" type="number" name="scale" min="5" max="100" step="5"
                    value="<?php ee($defaultPreset['scale']); ?>">
            </div>

            <!-- Platforms -->
            <div class="col-md-8">
                <label class="form-label">Target Platforms</label>
                <div class="d-flex flex-wrap gap-3 mt-1">
                    <?php foreach ($platforms as $slug => $profile): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox"
                                name="platforms[]"
                                value="<?php ee($slug); ?>"
                                id="plat_<?php ee($slug); ?>"
                                <?php echo in_array($slug, $defaultPlats, true) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="plat_<?php ee($slug); ?>">
                                <?php ee($profile['label']); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Auto-queue -->
            <div class="col-md-2">
                <label class="form-label">Auto-Queue</label>
                <select class="form-select" name="auto_queue">
                    <option value="1" <?php echo $defaultAutoQ ? 'selected' : ''; ?>>Yes</option>
                    <option value="0" <?php echo !$defaultAutoQ ? 'selected' : ''; ?>>No (clips only)</option>
                </select>
            </div>

            <!-- Schedule offset -->
            <div class="col-md-2">
                <label class="form-label">Queue Offset (hrs)</label>
                <input class="form-control" type="number" name="schedule_offset_hrs" min="0" max="720"
                    value="<?php ee((string) $defaultOffset); ?>">
            </div>

            <!-- Caption -->
            <div class="col-12">
                <label class="form-label">Caption Template</label>
                <textarea class="form-control" name="caption_template" rows="2"
                    placeholder="Caption for social posts. Leave blank to set later."></textarea>
            </div>
        </div>

        <div class="d-flex gap-3 align-items-center flex-wrap">
            <button class="btn btn-ptmd-primary btn-lg" type="submit" id="pipelineSubmitBtn">
                <i class="fa-solid fa-rocket me-2"></i>Start Pipeline
            </button>
            <span class="ptmd-muted small">
                Runs brand imaging → platform clips → social queue automatically.
                Requires FFmpeg.
            </span>
        </div>
    </form>

    <!-- Progress (hidden until job starts) -->
    <div id="pipelineProgress" class="mt-4" style="display:none">
        <p id="pipelineStatusText" class="ptmd-muted small mb-2">Starting…</p>
        <div class="ptmd-progress-bar">
            <div id="pipelineProgressBar" class="ptmd-progress-fill" role="progressbar"
                style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <div id="pipelineResult" class="mt-3"></div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Pipeline job table ────────────────────────────────────────────────── -->
<div class="ptmd-panel p-lg">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-list-check me-2 ptmd-text-teal"></i>Pipeline Jobs
    </h2>

    <?php if ($jobs): ?>
    <div class="table-responsive">
        <table class="ptmd-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Clip / Episode</th>
                    <th>Stage</th>
                    <th>Items</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jobs as $job): ?>
                <tr
                    data-pipeline-job="<?php ee((string) $job['id']); ?>"
                    data-status="<?php ee($job['status']); ?>"
                >
                    <td class="ptmd-muted">#<?php ee((string) $job['id']); ?></td>
                    <td>
                        <span class="fw-500"><?php ee($job['source_clip_label'] ?? '—'); ?></span>
                        <?php if ($job['episode_title']): ?>
                            <span class="d-block ptmd-muted" style="font-size:var(--text-xs)">
                                <?php ee($job['episode_title']); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="ptmd-badge-muted"><?php ee($job['current_stage']); ?></span>
                    </td>
                    <td class="ptmd-muted small">
                        <?php echo e($job['item_done']); ?> / <?php echo e($job['item_total']); ?>
                        <?php if ((int)$job['item_failed'] > 0): ?>
                            <span class="ms-1" style="color:var(--ptmd-error)">
                                (<?php echo e($job['item_failed']); ?> failed)
                            </span>
                        <?php endif; ?>
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
                        <div class="d-flex gap-2">
                            <a href="/admin/pipeline.php?view_job=<?php ee((string) $job['id']); ?>"
                               class="btn btn-ptmd-ghost btn-sm"
                               data-tippy-content="View items">
                                <i class="fa-solid fa-list"></i>
                            </a>
                            <?php if (!in_array($job['status'], ['completed','canceled'], true)): ?>
                                <form method="post" action="/admin/pipeline.php" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                    <input type="hidden" name="_action" value="cancel">
                                    <input type="hidden" name="job_id" value="<?php ee((string) $job['id']); ?>">
                                    <button class="btn btn-ptmd-ghost btn-sm" type="submit"
                                        style="color:var(--ptmd-error)"
                                        data-confirm="Cancel this pipeline job?"
                                        data-tippy-content="Cancel">
                                        <i class="fa-solid fa-xmark"></i>
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
        <p class="ptmd-muted small">No pipeline jobs yet. Select a clip above to get started.</p>
    <?php endif; ?>
</div>

<?php

// ── Inline job detail view ────────────────────────────────────────────────────
$viewJobId = isset($_GET['view_job']) ? (int) $_GET['view_job'] : 0;
if ($viewJobId && $pdo):
    $vJob = $pdo->prepare('SELECT * FROM pipeline_jobs WHERE id = :id LIMIT 1');
    $vJob->execute(['id' => $viewJobId]);
    $vJobRow = $vJob->fetch();

    $vItems = $pdo->prepare(
        'SELECT pi.*,
                vc.label AS clip_label,
                vc.output_path AS clip_output
         FROM pipeline_items pi
         LEFT JOIN video_clips vc ON vc.id = pi.video_clip_id
         WHERE pi.pipeline_job_id = :jid
         ORDER BY FIELD(pi.stage,"brand_imaging","clip_generation","queueing"), pi.id'
    );
    $vItems->execute(['jid' => $viewJobId]);
    $vItemRows = $vItems->fetchAll();

    if ($vJobRow):
?>
<div class="ptmd-panel p-lg mt-4">
    <h2 class="h6 mb-4">
        Job #<?php ee((string) $vJobRow['id']); ?> — <?php ee($vJobRow['label']); ?>
        <span class="ptmd-status ptmd-status-<?php ee($vJobRow['status']); ?> ms-2">
            <?php ee($vJobRow['status']); ?>
        </span>
    </h2>

    <?php if ($vJobRow['error_message']): ?>
        <div class="alert alert-danger py-2 small mb-3">
            <strong>Error:</strong> <?php ee($vJobRow['error_message']); ?>
        </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="ptmd-table">
            <thead>
                <tr>
                    <th>Stage</th>
                    <th>Platform</th>
                    <th>Status</th>
                    <th>Input</th>
                    <th>Output</th>
                    <th>Queue</th>
                    <th>Error</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vItemRows as $it): ?>
                <tr>
                    <td><span class="ptmd-badge-muted"><?php ee($it['stage']); ?></span></td>
                    <td class="ptmd-muted small"><?php ee($it['platform'] ?? '—'); ?></td>
                    <td>
                        <span class="ptmd-status ptmd-status-<?php ee($it['status']); ?>">
                            <?php ee($it['status']); ?>
                        </span>
                    </td>
                    <td style="font-size:var(--text-xs)"><?php ee($it['input_path'] ? basename((string)$it['input_path']) : '—'); ?></td>
                    <td style="font-size:var(--text-xs)">
                        <?php if ($it['output_path']): ?>
                            <a href="/uploads/<?php ee($it['output_path']); ?>" target="_blank" rel="noopener">
                                <?php echo e(basename((string)$it['output_path'])); ?>
                            </a>
                        <?php else: ?>
                            <span class="ptmd-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="ptmd-muted small">
                        <?php echo $it['queue_id'] ? '#' . e($it['queue_id']) : '—'; ?>
                    </td>
                    <td style="font-size:var(--text-xs);color:var(--ptmd-error)">
                        <?php ee($it['error_message'] ?? ''); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
    endif;
endif;
?>

<!-- ── Cron info panel ────────────────────────────────────────────────────── -->
<div class="ptmd-panel p-lg mt-4">
    <h2 class="h6 mb-3">
        <i class="fa-solid fa-clock me-2 ptmd-text-yellow"></i>Cron Scheduler
    </h2>
    <p class="ptmd-muted small mb-2">
        The cron scheduler worker dispatches due social queue entries automatically.
        Set a <strong>Cron Token</strong> in
        <a href="/admin/settings.php">Settings</a>,
        then add this command to your server cron (every 15 min recommended):
    </p>
    <?php if ($cronToken !== ''): ?>
        <code class="d-block p-3 rounded mb-0" style="background:var(--ptmd-surface-2);font-size:var(--text-xs);word-break:break-all">
            */15 * * * * curl -s "<?php echo e((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com')); ?>/api/cron_scheduler.php?token=<?php ee($cronToken); ?>" &gt; /dev/null 2&gt;&amp;1
        </code>
    <?php else: ?>
        <div class="ptmd-muted small">
            <i class="fa-solid fa-triangle-exclamation me-1" style="color:var(--ptmd-warning)"></i>
            No cron token set. Go to <a href="/admin/settings.php">Settings → system group</a>
            and set the <code>cron_token</code> value to a strong random string.
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    'use strict';

    const form       = document.getElementById('pipelineForm');
    const submitBtn  = document.getElementById('pipelineSubmitBtn');
    const progress   = document.getElementById('pipelineProgress');
    const statusText = document.getElementById('pipelineStatusText');
    const bar        = document.getElementById('pipelineProgressBar');
    const resultDiv  = document.getElementById('pipelineResult');

    if (!form) return;

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        const checkedPlatforms = form.querySelectorAll('input[name="platforms[]"]:checked');
        if (!checkedPlatforms.length) {
            alert('Please select at least one platform.');
            return;
        }

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Processing…';
        progress.style.display = 'block';
        statusText.textContent  = 'Submitting pipeline job…';
        bar.style.width         = '10%';
        resultDiv.innerHTML     = '';

        const body = new FormData(form);

        try {
            statusText.textContent = 'Brand imaging + clip generation in progress…';
            bar.style.width = '30%';

            const resp = await fetch('/api/pipeline_trigger.php', {
                method: 'POST',
                body: body,
            });
            const data = await resp.json();

            bar.style.width = '100%';

            if (data.ok) {
                statusText.textContent = data.message || 'Pipeline completed.';
                resultDiv.innerHTML = `
                    <div class="alert alert-success py-2 small">
                        <i class="fa-solid fa-check-circle me-1"></i>
                        ${data.message || 'Pipeline completed.'} Job ID: <strong>#${data.job_id}</strong>.
                        <a href="/admin/pipeline.php?view_job=${data.job_id}" class="ms-2">View items →</a>
                    </div>`;
            } else {
                statusText.textContent = 'Pipeline failed.';
                resultDiv.innerHTML = `
                    <div class="alert alert-danger py-2 small">
                        <i class="fa-solid fa-xmark-circle me-1"></i>
                        ${data.message || 'An error occurred.'}
                        ${data.job_id ? `<a href="/admin/pipeline.php?view_job=${data.job_id}" class="ms-2">View details →</a>` : ''}
                    </div>`;
            }
        } catch (err) {
            bar.style.width = '100%';
            statusText.textContent = 'Network error.';
            resultDiv.innerHTML = `<div class="alert alert-danger py-2 small">Network error: ${err.message}</div>`;
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fa-solid fa-rocket me-2"></i>Start Pipeline';
        }
    });
}());
</script>

<?php include __DIR__ . '/_admin_footer.php'; ?>

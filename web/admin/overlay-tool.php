<?php
/**
 * PTMD Admin — Overlay Tool
 *
 * PHP-based overlay selector with Canvas live preview.
 * Reads available overlay assets from /assets/brand/overlays/ and
 * /uploads/overlays/.  Clips are read from /uploads/clips/.
 *
 * Batch submission → /api/apply_overlays.php
 */

$pageTitle    = 'Overlay Tool | PTMD Admin';
$activePage   = 'overlay-tool';
$pageHeading  = 'Overlay Tool';
$pageSubheading = 'Select an overlay, pick clips, then apply in batch. Preview updates live in the canvas.';
$extraScripts = '<script src="/assets/js/overlay-tool.js"></script>';

include __DIR__ . '/_admin_head.php';

require_once __DIR__ . '/../inc/video_processor.php';

$pdo = get_db();

// ── Available overlays ────────────────────────────────────────────────────────
// Pull from brand overlay assets + any user-uploaded overlays in DB

$brandOverlayDir = $_SERVER['DOCUMENT_ROOT'] . '/assets/brand/overlays';
$brandOverlays   = [];

if (is_dir($brandOverlayDir)) {
    foreach (glob($brandOverlayDir . '/*.{png,gif,webp}', GLOB_BRACE) as $f) {
        $brandOverlays[] = [
            'path'  => '/assets/brand/overlays/' . basename($f),
            'label' => basename($f, '.' . pathinfo($f, PATHINFO_EXTENSION)),
            'type'  => 'brand',
        ];
    }
}

$dbOverlays = [];
if ($pdo) {
    $dbOverlays = $pdo->query(
        'SELECT id, filename, file_path FROM media_library WHERE category = "overlay" ORDER BY created_at DESC'
    )->fetchAll();
}

// ── Available clips ───────────────────────────────────────────────────────────
$clipDir    = $_SERVER['DOCUMENT_ROOT'] . '/uploads/clips';
$localClips = [];

if (is_dir($clipDir)) {
    foreach (glob($clipDir . '/*.{mp4,mov,webm}', GLOB_BRACE) as $f) {
        $localClips[] = [
            'path'  => '/uploads/clips/' . basename($f),
            'label' => basename($f),
        ];
    }
}

// Also pull processed clips from DB
$dbClips = [];
if ($pdo) {
    $dbClips = $pdo->query(
        'SELECT vc.id, vc.label, vc.source_path, vc.output_path, vc.status, e.title AS case_title
         FROM video_clips vc
         LEFT JOIN cases e ON e.id = vc.case_id
         WHERE vc.status IN ("raw","ready")
         ORDER BY vc.created_at DESC LIMIT 50'
    )->fetchAll();
}

// ── Recent batch jobs ─────────────────────────────────────────────────────────
$batchJobs = [];
if ($pdo) {
    $batchJobs = $pdo->query(
        'SELECT j.*, u.username AS created_by_name
         FROM overlay_batch_jobs j
         LEFT JOIN users u ON u.id = j.created_by
         ORDER BY j.created_at DESC LIMIT 20'
    )->fetchAll();
}
?>

<!-- Two-column editor + clip browser -->
<div class="row g-4 mb-4">

    <!-- Left: Overlay selector -->
    <div class="col-lg-4">
        <div class="ptmd-panel p-lg h-100">
            <h2 class="h6 mb-3">
                <i class="fa-solid fa-image me-2 ptmd-text-teal"></i>Select Overlay
            </h2>

            <?php if (!$brandOverlays && !$dbOverlays): ?>
                <div class="ptmd-muted small">
                    No overlay assets found in <code>/assets/brand/overlays/</code> or media library.
                    <a href="/admin/media.php">Upload one via Media Library</a>.
                </div>
            <?php endif; ?>

            <!-- Brand overlays -->
            <?php if ($brandOverlays): ?>
                <p class="ptmd-muted" style="font-size:var(--text-xs);letter-spacing:.06em;text-transform:uppercase;margin-bottom:.75rem">
                    Brand Assets
                </p>
                <div class="overlay-selector-grid mb-4">
                    <?php foreach ($brandOverlays as $ov): ?>
                        <div
                            class="overlay-swatch"
                            data-overlay-path="<?php ee($ov['path']); ?>"
                            data-tippy-content="<?php ee($ov['label']); ?>"
                        >
                            <img
                                src="<?php ee($ov['path']); ?>"
                                alt="<?php ee($ov['label']); ?>"
                                loading="lazy"
                                onerror="this.parentElement.style.opacity='.4'"
                            >
                            <span class="overlay-swatch-check"><i class="fa-solid fa-check" style="font-size:9px"></i></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- DB-uploaded overlays -->
            <?php if ($dbOverlays): ?>
                <p class="ptmd-muted" style="font-size:var(--text-xs);letter-spacing:.06em;text-transform:uppercase;margin-bottom:.75rem">
                    Uploaded Overlays
                </p>
                <div class="overlay-selector-grid">
                    <?php foreach ($dbOverlays as $ov): ?>
                        <div
                            class="overlay-swatch"
                            data-overlay-path="/uploads/<?php ee($ov['file_path']); ?>"
                            data-tippy-content="<?php ee($ov['filename']); ?>"
                        >
                            <img
                                src="/uploads/<?php ee($ov['file_path']); ?>"
                                alt="<?php ee($ov['filename']); ?>"
                                loading="lazy"
                                onerror="this.parentElement.style.opacity='.4'"
                            >
                            <span class="overlay-swatch-check"><i class="fa-solid fa-check" style="font-size:9px"></i></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Center: Canvas preview + controls -->
    <div class="col-lg-4">
        <div class="ptmd-panel p-lg h-100 d-flex flex-column gap-4">
            <div>
                <h2 class="h6 mb-3">
                    <i class="fa-solid fa-eye me-2 ptmd-text-yellow"></i>Live Preview
                </h2>
                <div class="ptmd-preview-canvas-wrap">
                    <canvas id="overlayPreviewCanvas" aria-label="Overlay preview"></canvas>
                </div>
            </div>

            <!-- Position picker -->
            <div>
                <label class="form-label mb-2">
                    <i class="fa-solid fa-arrows-to-dot me-1"></i>Position
                </label>
                <div class="d-grid gap-1" style="grid-template-columns:repeat(3,1fr)">
                    <?php
                    $positions = [
                        'top-left'     => '<i class="fa-solid fa-arrow-up-left"></i>',
                        'top-right'    => '<i class="fa-solid fa-arrow-up-right"></i>',
                        'center'       => '<i class="fa-solid fa-crosshairs"></i>',
                        'bottom-left'  => '<i class="fa-solid fa-arrow-down-left"></i>',
                        'bottom-right' => '<i class="fa-solid fa-arrow-down-right"></i>',
                        'full'         => '<i class="fa-solid fa-expand"></i>',
                    ];
                    foreach ($positions as $pos => $icon):
                    ?>
                        <button
                            type="button"
                            class="btn btn-ptmd-outline btn-sm <?php echo $pos === 'bottom-right' ? 'active btn-ptmd-teal' : ''; ?>"
                            data-position="<?php ee($pos); ?>"
                            data-tippy-content="<?php ee($pos); ?>"
                        >
                            <?php echo $icon; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Sliders -->
            <div>
                <div class="d-flex justify-content-between mb-1">
                    <label class="form-label mb-0">
                        <i class="fa-solid fa-droplet me-1"></i>Opacity
                    </label>
                    <span id="opacityLabel" class="ptmd-text-teal small fw-600">100%</span>
                </div>
                <input
                    type="range"
                    class="form-range"
                    id="overlayOpacity"
                    min="0" max="1" step="0.05" value="1"
                >
            </div>

            <div>
                <div class="d-flex justify-content-between mb-1">
                    <label class="form-label mb-0">
                        <i class="fa-solid fa-maximize me-1"></i>Overlay Scale
                    </label>
                    <span id="scaleLabel" class="ptmd-text-teal small fw-600">30%</span>
                </div>
                <input
                    type="range"
                    class="form-range"
                    id="overlayScale"
                    min="5" max="100" step="5" value="30"
                >
            </div>

        </div>
    </div>

    <!-- Right: Clip browser -->
    <div class="col-lg-4">
        <div class="ptmd-panel p-lg h-100 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h6 mb-0">
                    <i class="fa-solid fa-film me-2 ptmd-text-teal"></i>Clips
                </h2>
                <span id="selectedClipsCount" class="ptmd-muted" style="font-size:var(--text-xs)">
                    No clips selected
                </span>
            </div>
            <p class="ptmd-muted small mb-3">
                <kbd>Ctrl</kbd> + click for multi-select.
                First selected clip previews on the canvas.
            </p>

            <?php if (!$dbClips && !$localClips): ?>
                <div class="ptmd-muted small">
                    No clips found. Upload clips via
                    <a href="/admin/video-processor.php">Video Processor</a>
                    or the <a href="/admin/media.php">Media Library</a>.
                </div>
            <?php endif; ?>

            <div class="clip-browser-grid overflow-auto flex-grow-1" style="max-height:380px">
                <?php foreach ($dbClips as $clip): ?>
                    <div
                        class="clip-thumbnail-item"
                        data-clip-path="/uploads/<?php ee($clip['output_path'] ?? $clip['source_path']); ?>"
                        data-clip-id="<?php ee((string) $clip['id']); ?>"
                        data-tippy-content="<?php ee($clip['label']); ?>"
                    >
                        <video
                            src="/uploads/<?php ee($clip['output_path'] ?? $clip['source_path']); ?>"
                            preload="metadata"
                            muted
                            style="pointer-events:none"
                        ></video>
                        <div class="clip-name"><?php ee($clip['label']); ?></div>
                        <div class="clip-select-check"><i class="fa-solid fa-check" style="font-size:10px"></i></div>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($localClips as $clip): ?>
                    <div
                        class="clip-thumbnail-item"
                        data-clip-path="<?php ee($clip['path']); ?>"
                        data-tippy-content="<?php ee($clip['label']); ?>"
                    >
                        <video
                            src="<?php ee($clip['path']); ?>"
                            preload="metadata"
                            muted
                            style="pointer-events:none"
                        ></video>
                        <div class="clip-name"><?php ee($clip['label']); ?></div>
                        <div class="clip-select-check"><i class="fa-solid fa-check" style="font-size:10px"></i></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div>

<!-- Batch submit form -->
<div class="ptmd-panel p-xl mb-5">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-layer-group me-2 ptmd-text-teal"></i>Batch Apply Overlays
    </h2>
    <form id="batchForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label" for="batchLabel">Batch Label</label>
                <input
                    class="form-control"
                    id="batchLabel"
                    name="label"
                    placeholder="e.g. case 1 — Teaser Clips"
                >
            </div>
        </div>
        <div class="d-flex gap-3 align-items-center flex-wrap">
            <button class="btn btn-ptmd-primary btn-lg" type="submit" id="submitBatchBtn">
                <i class="fa-solid fa-layer-group me-2"></i>Apply Overlays to Selected Clips
            </button>
            <span class="ptmd-muted small">
                Selected clips + overlay + settings above will be sent to FFmpeg for processing.
            </span>
        </div>
    </form>

    <!-- Progress (hidden until a job starts) -->
    <div id="batchProgressSection" class="mt-4" style="display:none">
        <p id="batchStatusText" class="ptmd-muted small mb-2">Starting…</p>
        <div class="ptmd-progress-bar">
            <div
                id="batchProgressBar"
                class="ptmd-progress-fill"
                role="progressbar"
                style="width:0%"
                aria-valuenow="0"
                aria-valuemin="0"
                aria-valuemax="100"
            ></div>
        </div>
    </div>
</div>

<!-- Batch history table -->
<div class="ptmd-panel p-lg">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-clock-rotate-left me-2 ptmd-text-teal"></i>Batch History
    </h2>

    <?php if ($batchJobs): ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Label</th>
                        <th>Overlay</th>
                        <th>Position</th>
                        <th>Clips</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($batchJobs as $job): ?>
                        <tr
                            data-poll-job="<?php ee((string) $job['id']); ?>"
                            data-status="<?php ee($job['status']); ?>"
                        >
                            <td class="ptmd-muted">#<?php ee((string) $job['id']); ?></td>
                            <td><?php ee($job['label']); ?></td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php echo e(basename((string) $job['overlay_path'])); ?>
                            </td>
                            <td><span class="ptmd-badge-muted"><?php ee($job['position']); ?></span></td>
                            <td>
                                <?php echo e($job['done_items']); ?> / <?php echo e($job['total_items']); ?>
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
                                <a
                                    href="/admin/overlay-tool.php?view_job=<?php ee((string) $job['id']); ?>"
                                    class="btn btn-ptmd-ghost btn-sm"
                                    data-tippy-content="View items"
                                >
                                    <i class="fa-solid fa-list"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="ptmd-muted small">No batch jobs yet. Select clips and an overlay above to get started.</p>
    <?php endif; ?>
</div>

<?php

// ── View individual batch job items ────────────────────────────────────────────
$viewJobId = isset($_GET['view_job']) ? (int) $_GET['view_job'] : 0;
if ($viewJobId && $pdo) {
    $batchJob   = $pdo->prepare('SELECT * FROM overlay_batch_jobs WHERE id = :id');
    $batchJob->execute(['id' => $viewJobId]);
    $jobDetail  = $batchJob->fetch();

    $itemsStmt = $pdo->prepare(
        'SELECT * FROM overlay_batch_items WHERE batch_job_id = :jid ORDER BY id'
    );
    $itemsStmt->execute(['jid' => $viewJobId]);
    $jobItems  = $itemsStmt->fetchAll();

    if ($jobDetail):
?>
<div class="ptmd-panel p-lg mt-4">
    <h2 class="h6 mb-4">
        Batch Job #<?php ee((string) $jobDetail['id']); ?> — <?php ee($jobDetail['label']); ?>
    </h2>
    <table class="ptmd-table">
        <thead>
            <tr><th>Item</th><th>Source</th><th>Output</th><th>Status</th><th>Error</th></tr>
        </thead>
        <tbody>
            <?php foreach ($jobItems as $item): ?>
                <tr>
                    <td class="ptmd-muted">#<?php ee((string) $item['id']); ?></td>
                    <td style="font-size:var(--text-xs)"><?php ee(basename((string) $item['source_path'])); ?></td>
                    <td style="font-size:var(--text-xs)">
                        <?php if ($item['output_path']): ?>
                            <a href="/uploads/<?php ee($item['output_path']); ?>" target="_blank" rel="noopener">
                                <?php echo e(basename((string) $item['output_path'])); ?>
                            </a>
                        <?php else: ?>
                            <span class="ptmd-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="ptmd-status ptmd-status-<?php ee($item['status']); ?>">
                            <?php ee($item['status']); ?>
                        </span>
                    </td>
                    <td style="font-size:var(--text-xs);color:var(--ptmd-error)">
                        <?php ee($item['error_message'] ?? ''); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
    endif;
}

include __DIR__ . '/_admin_footer.php';
?>

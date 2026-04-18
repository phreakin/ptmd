<?php
/**
 * PTMD Admin — Experiments
 */

$pageTitle      = 'Experiments | PTMD Admin';
$activePage     = 'experiments';
$pageHeading    = 'Experiments';
$pageSubheading = 'Create and manage A/B experiments for hooks, titles, thumbnails, and more.';

include __DIR__ . '/_admin_head.php';

$pdo = get_db();

$experiments = [];
$stats       = ['running' => 0, 'completed' => 0, 'total_events' => 0];

if ($pdo) {
    try {
        $experiments = $pdo->query(
            'SELECT e.*,
                    u.username AS created_by_name,
                    COUNT(ev.id) AS total_events
             FROM experiment_runs e
             LEFT JOIN users u ON u.id = e.created_by
             LEFT JOIN experiment_events ev ON ev.experiment_id = e.id
             GROUP BY e.id
             ORDER BY e.created_at DESC
             LIMIT 30'
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}

    try {
        $sr = $pdo->query(
            'SELECT
                SUM(status = \'running\') AS running,
                SUM(status = \'completed\') AS completed,
                (SELECT COUNT(*) FROM experiment_events) AS total_events
             FROM experiment_runs'
        )->fetch(\PDO::FETCH_ASSOC);
        if ($sr) {
            $stats = [
                'running'      => (int) ($sr['running'] ?? 0),
                'completed'    => (int) ($sr['completed'] ?? 0),
                'total_events' => (int) ($sr['total_events'] ?? 0),
            ];
        }
    } catch (\Throwable $e) {}
}

$experimentTypes = ['hook', 'title', 'thumbnail', 'caption', 'cta', 'posting_time'];
?>
<meta name="csrf-token" content="<?php echo csrf_token(); ?>">

<!-- Stats Row -->
<div class="row g-4 mb-5">
    <div class="col-12 col-md-4">
        <div class="ptmd-panel p-3 text-center">
            <div class="fw-bold" style="font-size:2rem;color:var(--bs-success)"><?php echo $stats['running']; ?></div>
            <div class="ptmd-muted small mt-1"><i class="fa-solid fa-play me-1"></i>Running Experiments</div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="ptmd-panel p-3 text-center">
            <div class="fw-bold" style="font-size:2rem;color:var(--bs-info)"><?php echo $stats['completed']; ?></div>
            <div class="ptmd-muted small mt-1"><i class="fa-solid fa-flag-checkered me-1"></i>Completed</div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="ptmd-panel p-3 text-center">
            <div class="ptmd-text-teal fw-bold" style="font-size:2rem"><?php echo number_format($stats['total_events']); ?></div>
            <div class="ptmd-muted small mt-1"><i class="fa-solid fa-chart-line me-1"></i>Total Events Tracked</div>
        </div>
    </div>
</div>

<!-- Create Experiment (collapsible) -->
<div class="ptmd-panel p-lg mb-5">
    <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fa-solid fa-flask me-2 ptmd-text-teal"></i>Create New Experiment</h5>
        <button class="btn btn-ptmd-ghost btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#createExperimentCollapse" aria-expanded="false" aria-controls="createExperimentCollapse">
            <i class="fa-solid fa-plus me-1"></i>Expand
        </button>
    </div>
    <div class="collapse mt-4" id="createExperimentCollapse">
        <div class="h-divider mb-4"></div>
        <form id="createExperimentForm" novalidate>
            <div class="row g-3">
                <div class="col-lg-6">
                    <label class="form-label small ptmd-muted">Experiment Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Hook A/B – Hospital Billing" required>
                </div>
                <div class="col-lg-3">
                    <label class="form-label small ptmd-muted">Type <span class="text-danger">*</span></label>
                    <select name="experiment_type" class="form-select" required>
                        <option value="">— Select —</option>
                        <?php foreach ($experimentTypes as $type): ?>
                            <option value="<?php echo $type; ?>"><?php echo ucfirst(str_replace('_', ' ', $type)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-3">
                    <label class="form-label small ptmd-muted">Min Sample Size</label>
                    <input type="number" name="min_sample_size" class="form-control" min="10" value="100">
                </div>
                <div class="col-lg-9">
                    <label class="form-label small ptmd-muted">Hypothesis</label>
                    <input type="text" name="hypothesis" class="form-control" placeholder="e.g. Direct questions will increase retention by 15%">
                </div>
                <div class="col-lg-3">
                    <label class="form-label small ptmd-muted">Target Confidence (%)</label>
                    <input type="number" name="target_confidence" class="form-control" min="0" max="100" value="95">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-ptmd-primary" id="createExperimentBtn">
                        <i class="fa-solid fa-flask me-2"></i>Create Experiment
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Experiments Table -->
<div class="ptmd-panel p-lg mb-5">
    <h5 class="mb-3"><i class="fa-solid fa-table me-2 ptmd-text-teal"></i>All Experiments</h5>
    <?php if (empty($experiments)): ?>
        <p class="ptmd-muted small">No experiments yet.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table class="ptmd-table table table-dark table-hover align-middle mb-0">
        <thead>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Status</th>
                <th>Hypothesis</th>
                <th>Sample Size</th>
                <th>Events</th>
                <th>Winner</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($experiments as $exp): ?>
            <tr>
                <td class="fw-500"><?php ee($exp['name'] ?? '—'); ?></td>
                <td>
                    <?php
                    $typeBg = match($exp['experiment_type'] ?? '') {
                        'hook'         => 'bg-info text-dark',
                        'title'        => 'bg-primary',
                        'thumbnail'    => 'bg-warning text-dark',
                        'caption'      => 'bg-secondary',
                        'cta'          => 'bg-success',
                        'posting_time' => 'bg-danger',
                        default        => 'bg-secondary',
                    };
                    ?>
                    <span class="badge <?php echo $typeBg; ?>" style="font-size:0.7rem">
                        <?php ee(str_replace('_', ' ', $exp['experiment_type'] ?? '')); ?>
                    </span>
                </td>
                <td>
                    <?php
                    $status  = $exp['status'] ?? 'draft';
                    $statBg  = match($status) {
                        'running'   => 'bg-success',
                        'completed' => 'bg-info text-dark',
                        'paused'    => 'bg-warning text-dark',
                        default     => 'bg-secondary',
                    };
                    ?>
                    <span class="badge <?php echo $statBg; ?>"><?php ee($status); ?></span>
                </td>
                <td class="ptmd-muted small" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?php ee(mb_strimwidth($exp['hypothesis'] ?? '—', 0, 60, '…')); ?>
                </td>
                <td class="ptmd-muted"><?php echo (int) ($exp['min_sample_size'] ?? 0); ?></td>
                <td class="ptmd-muted"><?php echo (int) ($exp['total_events'] ?? 0); ?></td>
                <td>
                    <?php if (!empty($exp['winner_variant_id'])): ?>
                        <span class="badge bg-success">#<?php echo (int) $exp['winner_variant_id']; ?></span>
                    <?php else: ?>
                        <span class="ptmd-muted small">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="d-flex gap-1 flex-wrap">
                        <button class="btn btn-ptmd-ghost btn-sm view-experiment-btn"
                                data-exp-id="<?php echo (int) $exp['id']; ?>">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                        <?php if ($status === 'draft' || $status === 'paused'): ?>
                        <button class="btn btn-ptmd-primary btn-sm experiment-action-btn"
                                data-exp-id="<?php echo (int) $exp['id']; ?>"
                                data-action="start"
                                title="Start">
                            <i class="fa-solid fa-play"></i>
                        </button>
                        <?php endif; ?>
                        <?php if ($status === 'running'): ?>
                        <button class="btn btn-ptmd-secondary btn-sm experiment-action-btn"
                                data-exp-id="<?php echo (int) $exp['id']; ?>"
                                data-action="pause"
                                title="Pause">
                            <i class="fa-solid fa-pause"></i>
                        </button>
                        <button class="btn btn-ptmd-outline btn-sm experiment-action-btn"
                                data-exp-id="<?php echo (int) $exp['id']; ?>"
                                data-action="complete"
                                title="Complete">
                            <i class="fa-solid fa-flag-checkered"></i>
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-ptmd-ghost btn-sm add-variant-btn"
                                data-exp-id="<?php echo (int) $exp['id']; ?>"
                                title="Add Variant">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- Experiment Detail Panel (initially hidden) -->
<div id="experimentDetail" class="ptmd-panel p-lg mb-4" style="display:none">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="mb-0"><i class="fa-solid fa-vials me-2 ptmd-text-teal"></i>Experiment Detail</h5>
        <button class="btn btn-ptmd-ghost btn-sm" id="experimentDetailCloseBtn">
            <i class="fa-solid fa-xmark me-1"></i>Close
        </button>
    </div>
    <div id="experimentDetailMeta" class="mb-3"></div>
    <h6 class="ptmd-muted mb-3">Variants</h6>
    <div class="table-responsive">
    <table class="ptmd-table table table-dark align-middle mb-0">
        <thead>
            <tr>
                <th>Variant Key</th>
                <th>Content</th>
                <th>Allocation</th>
                <th>Events</th>
                <th>Winner</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="experimentVariantsTbody"></tbody>
    </table>
    </div>
</div>

<?php
$extraScripts = '<script type="module" src="/assets/js/admin/experiments.js"></script>';
include __DIR__ . '/_admin_footer.php';
?>

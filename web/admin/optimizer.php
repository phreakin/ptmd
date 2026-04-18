<?php
/**
 * PTMD Admin — Optimizer Engine
 */

$pageTitle      = 'Optimizer | PTMD Admin';
$activePage     = 'optimizer';
$pageHeading    = 'Optimizer Engine';
$pageSubheading = 'Run optimization passes on cases and clips, review AI-scored results.';

include __DIR__ . '/_admin_head.php';

require_once __DIR__ . '/../inc/services/OptimizerService.php';

$pdo = get_db();

$recentRuns    = [];
$pendingReview = [];
$stats         = ['total' => 0, 'pending' => 0, 'avg_confidence' => 0];
$allCases      = [];

if ($pdo) {
    try {
        $recentRuns = $pdo->query(
            'SELECT r.*, c.title AS case_title
             FROM optimizer_runs r
             LEFT JOIN cases c ON c.id = r.target_id AND r.target_type = \'case\'
             ORDER BY r.created_at DESC
             LIMIT 30'
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}

    try {
        $pendingReview = $pdo->query(
            'SELECT * FROM optimizer_runs
             WHERE decision = \'human_review\'
               AND requires_approval = 1
               AND approved_by IS NULL
             ORDER BY created_at DESC
             LIMIT 20'
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}

    try {
        $sr = $pdo->query(
            'SELECT
                COUNT(*) AS total,
                SUM(decision = \'human_review\' AND requires_approval = 1 AND approved_by IS NULL) AS pending,
                AVG(confidence_score) AS avg_confidence
             FROM optimizer_runs'
        )->fetch(\PDO::FETCH_ASSOC);
        if ($sr) {
            $stats = [
                'total'          => (int) ($sr['total'] ?? 0),
                'pending'        => (int) ($sr['pending'] ?? 0),
                'avg_confidence' => round((float) ($sr['avg_confidence'] ?? 0), 1),
            ];
        }
    } catch (\Throwable $e) {}

    try {
        $allCases = $pdo->query(
            'SELECT id, title FROM cases ORDER BY title'
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}
?>
<meta name="csrf-token" content="<?php echo csrf_token(); ?>">

<!-- Stats Row -->
<div class="row g-4 mb-5">
    <div class="col-12 col-md-4">
        <div class="ptmd-panel p-3 text-center">
            <div class="ptmd-text-teal fw-bold" style="font-size:2rem"><?php echo $stats['total']; ?></div>
            <div class="ptmd-muted small mt-1"><i class="fa-solid fa-gears me-1"></i>Total Runs</div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="ptmd-panel p-3 text-center">
            <div class="fw-bold" style="font-size:2rem;color:var(--bs-warning)"><?php echo $stats['pending']; ?></div>
            <div class="ptmd-muted small mt-1"><i class="fa-solid fa-user-clock me-1"></i>Pending Human Review</div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="ptmd-panel p-3 text-center">
            <div class="fw-bold" style="font-size:2rem;color:var(--bs-info)"><?php echo $stats['avg_confidence']; ?>%</div>
            <div class="ptmd-muted small mt-1"><i class="fa-solid fa-chart-simple me-1"></i>Avg Confidence Score</div>
        </div>
    </div>
</div>

<!-- Run New Optimizer -->
<div class="ptmd-panel p-lg mb-5">
    <h5 class="mb-4"><i class="fa-solid fa-play-circle me-2 ptmd-text-teal"></i>Run Optimizer</h5>
    <form id="optimizerRunForm" novalidate>
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label small ptmd-muted">Target Type <span class="text-danger">*</span></label>
                <select name="target_type" id="optimizerTargetType" class="form-select" required>
                    <option value="">— Select Type —</option>
                    <option value="case">Case</option>
                    <option value="clip">Clip</option>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label small ptmd-muted">Target <span class="text-danger">*</span></label>
                <select name="target_id" id="optimizerTargetId" class="form-select" required disabled>
                    <option value="">— Select target type first —</option>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small ptmd-muted">Platform</label>
                <select name="platform" class="form-select">
                    <option value="">All Platforms</option>
                    <option value="youtube">YouTube</option>
                    <option value="tiktok">TikTok</option>
                    <option value="instagram">Instagram</option>
                    <option value="facebook">Facebook</option>
                    <option value="x">X / Twitter</option>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-ptmd-primary w-100" id="optimizerRunBtn">
                    <i class="fa-solid fa-bolt me-2"></i>Run Optimizer
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Results Panel (initially hidden) -->
<div id="optimizerResults" class="ptmd-panel p-lg mb-5" style="display:none">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="mb-0"><i class="fa-solid fa-magnifying-glass-chart me-2 ptmd-text-teal"></i>Optimizer Results</h5>
        <div class="d-flex gap-2" id="optimizerResultBadges"></div>
    </div>
    <div class="row g-4">
        <!-- Score + meta -->
        <div class="col-lg-4">
            <div class="ptmd-panel p-3 text-center mb-3">
                <div class="ptmd-muted small mb-1">Optimization Score</div>
                <div id="optimizerScore" class="fw-bold" style="font-size:3rem;color:var(--bs-info)">—</div>
            </div>
            <div class="d-flex gap-2 justify-content-center flex-wrap" id="optimizerMetaBadges"></div>
        </div>
        <!-- Factors table -->
        <div class="col-lg-8">
            <h6 class="ptmd-muted mb-3">Score Factors</h6>
            <div class="table-responsive">
                <table class="ptmd-table table table-dark align-middle mb-0" id="optimizerFactorsTable">
                    <thead>
                        <tr>
                            <th>Factor</th>
                            <th>Contribution</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="optimizerFactorsTbody"></tbody>
                </table>
            </div>
        </div>
    </div>
    <!-- Variants -->
    <div class="mt-4" id="optimizerVariantsWrap">
        <h6 class="ptmd-muted mb-3">Generated Variants</h6>
        <div class="row g-3" id="optimizerVariantsRow"></div>
    </div>
    <div class="mt-3 d-flex gap-2">
        <button class="btn btn-ptmd-ghost btn-sm" id="optimizerExplainBtn">
            <i class="fa-solid fa-lightbulb me-1"></i>Explain Decision
        </button>
    </div>
</div>

<!-- Explain Modal -->
<div class="modal fade" id="explainModal" tabindex="-1" aria-labelledby="explainModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:var(--ptmd-surface,#1a1a2e)">
            <div class="modal-header border-0">
                <h5 class="modal-title" id="explainModalLabel"><i class="fa-solid fa-lightbulb me-2 ptmd-text-teal"></i>Decision Explanation</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="explainModalBody">
                <p class="ptmd-muted">Loading explanation…</p>
            </div>
        </div>
    </div>
</div>

<!-- Pending Human Review -->
<div class="ptmd-panel p-lg mb-5">
    <h5 class="mb-3"><i class="fa-solid fa-user-check me-2" style="color:var(--bs-warning)"></i>Pending Human Review</h5>
    <?php if (empty($pendingReview)): ?>
        <p class="ptmd-muted small">No runs pending review.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table class="ptmd-table table table-dark table-hover align-middle mb-0">
        <thead>
            <tr>
                <th>Run ID</th>
                <th>Target</th>
                <th>Score</th>
                <th>Confidence</th>
                <th>Decision</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($pendingReview as $run): ?>
            <tr>
                <td class="ptmd-muted small">#<?php echo (int) $run['id']; ?></td>
                <td>
                    <span class="badge bg-secondary me-1"><?php ee($run['target_type'] ?? ''); ?></span>
                    <?php ee($run['case_title'] ?? ('#' . (int) ($run['target_id'] ?? 0))); ?>
                </td>
                <td>
                    <?php $sc = (float) ($run['score'] ?? 0); $scBg = $sc >= 75 ? 'bg-success' : ($sc >= 50 ? 'bg-warning text-dark' : 'bg-danger'); ?>
                    <span class="badge <?php echo $scBg; ?>"><?php echo number_format($sc, 1); ?></span>
                </td>
                <td>
                    <?php $conf = (float) ($run['confidence_score'] ?? 0); ?>
                    <span class="badge bg-info text-dark"><?php echo number_format($conf, 1); ?>%</span>
                </td>
                <td>
                    <span class="badge bg-warning text-dark"><?php ee($run['decision'] ?? ''); ?></span>
                </td>
                <td class="ptmd-muted small"><?php echo $run['created_at'] ? e(date('M j, H:i', strtotime($run['created_at']))) : '—'; ?></td>
                <td>
                    <button class="btn btn-ptmd-primary btn-sm view-run-btn"
                            data-run-id="<?php echo (int) $run['id']; ?>">
                        <i class="fa-solid fa-eye me-1"></i>View
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- Recent Runs -->
<div class="ptmd-panel p-lg mb-4">
    <h5 class="mb-3"><i class="fa-solid fa-clock-rotate-left me-2 ptmd-text-teal"></i>Recent Runs (Last 30)</h5>
    <?php if (empty($recentRuns)): ?>
        <p class="ptmd-muted small">No runs yet.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table class="ptmd-table table table-dark table-hover align-middle mb-0">
        <thead>
            <tr>
                <th>Run ID</th>
                <th>Target</th>
                <th>Score</th>
                <th>Confidence</th>
                <th>Decision</th>
                <th>Approved By</th>
                <th>Approved At</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recentRuns as $run): ?>
            <tr>
                <td class="ptmd-muted small">#<?php echo (int) $run['id']; ?></td>
                <td>
                    <span class="badge bg-secondary me-1"><?php ee($run['target_type'] ?? ''); ?></span>
                    <?php ee($run['case_title'] ?? ('#' . (int) ($run['target_id'] ?? 0))); ?>
                </td>
                <td>
                    <?php
                    $sc   = (float) ($run['score'] ?? 0);
                    $scBg = $sc >= 75 ? 'bg-success' : ($sc >= 50 ? 'bg-warning text-dark' : 'bg-danger');
                    ?>
                    <span class="badge <?php echo $scBg; ?>"><?php echo number_format($sc, 1); ?></span>
                </td>
                <td>
                    <span class="badge bg-info text-dark"><?php echo number_format((float) ($run['confidence_score'] ?? 0), 1); ?>%</span>
                </td>
                <td>
                    <?php
                    $dec   = $run['decision'] ?? '';
                    $decBg = match($dec) {
                        'accept'       => 'bg-success',
                        'reject'       => 'bg-danger',
                        'human_review' => 'bg-warning text-dark',
                        default        => 'bg-secondary',
                    };
                    ?>
                    <span class="badge <?php echo $decBg; ?>"><?php ee($dec); ?></span>
                </td>
                <td class="ptmd-muted small"><?php ee($run['approved_by'] ? '#' . (int) $run['approved_by'] : '—'); ?></td>
                <td class="ptmd-muted small"><?php echo $run['approved_at'] ? e(date('M j, H:i', strtotime($run['approved_at']))) : '—'; ?></td>
                <td class="ptmd-muted small"><?php echo $run['created_at'] ? e(date('M j, H:i', strtotime($run['created_at']))) : '—'; ?></td>
                <td>
                    <button class="btn btn-ptmd-ghost btn-sm view-run-btn"
                            data-run-id="<?php echo (int) $run['id']; ?>">
                        <i class="fa-solid fa-eye me-1"></i>View
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php
$extraScripts = '<script type="module" src="/assets/js/admin/optimizer.js"></script>';
include __DIR__ . '/_admin_footer.php';
?>

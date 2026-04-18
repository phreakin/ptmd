<?php
/**
 * PTMD Admin — Trend Intake
 */

$pageTitle      = 'Trend Intake | PTMD Admin';
$activePage     = 'trends';
$pageHeading    = 'Trend Intake';
$pageSubheading = 'Monitor, ingest, and manage trend signals and clusters.';

include __DIR__ . '/_admin_head.php';

require_once __DIR__ . '/../inc/services/TrendIntakeService.php';

$pdo = get_db();

$activeClusters = [];
$recentSignals  = [];
$sources        = [];
$stats          = ['raw' => 0, 'clustered' => 0, 'promoted' => 0, 'expired' => 0];

try {
    $activeClusters = ptmd_trend_get_active_clusters(20);
} catch (\Throwable $e) {}

if ($pdo) {
    try {
        $recentSignals = $pdo->query(
            'SELECT * FROM trend_signals ORDER BY created_at DESC LIMIT 50'
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}

    try {
        $sources = $pdo->query(
            'SELECT * FROM trend_sources ORDER BY display_name'
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}

    try {
        $sr = $pdo->query(
            "SELECT
                SUM(status='raw') AS raw,
                SUM(status='clustered') AS clustered,
                SUM(status='promoted') AS promoted,
                SUM(status IN ('expired','rejected')) AS expired
             FROM trend_signals"
        )->fetch(\PDO::FETCH_ASSOC);
        if ($sr) {
            $stats = [
                'raw'       => (int) ($sr['raw'] ?? 0),
                'clustered' => (int) ($sr['clustered'] ?? 0),
                'promoted'  => (int) ($sr['promoted'] ?? 0),
                'expired'   => (int) ($sr['expired'] ?? 0),
            ];
        }
    } catch (\Throwable $e) {}
}

$totalSignals = array_sum($stats);
?>
<meta name="csrf-token" content="<?php echo csrf_token(); ?>">

<!-- Stats Row -->
<div class="row g-4 mb-5">
    <div class="col-6 col-lg-3">
        <div class="ptmd-panel p-3 text-center">
            <div class="ptmd-text-teal fw-bold" style="font-size:2rem"><?php echo $totalSignals; ?></div>
            <div class="ptmd-muted small mt-1"><i class="fa-solid fa-signal me-1"></i>Total Signals</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ptmd-panel p-3 text-center">
            <div class="fw-bold" style="font-size:2rem;color:var(--bs-info)"><?php echo count($activeClusters); ?></div>
            <div class="ptmd-muted small mt-1"><i class="fa-solid fa-layer-group me-1"></i>Active Clusters</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ptmd-panel p-3 text-center">
            <div class="fw-bold" style="font-size:2rem;color:var(--bs-success)"><?php echo $stats['promoted']; ?></div>
            <div class="ptmd-muted small mt-1"><i class="fa-solid fa-arrow-up-right-dots me-1"></i>Promoted to Cases</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ptmd-panel p-3 text-center">
            <div class="fw-bold" style="font-size:2rem;color:var(--bs-danger)"><?php echo $stats['expired']; ?></div>
            <div class="ptmd-muted small mt-1"><i class="fa-solid fa-clock-rotate-left me-1"></i>Expired / Rejected</div>
        </div>
    </div>
</div>

<!-- Two-column: clusters table + ingest form -->
<div class="row g-4 mb-5">
    <!-- Active Clusters table -->
    <div class="col-lg-8">
        <div class="ptmd-panel p-lg">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><i class="fa-solid fa-fire me-2 ptmd-text-teal"></i>Active Trend Clusters</h5>
                <button class="btn btn-ptmd-ghost btn-sm" id="refreshClustersBtn">
                    <i class="fa-solid fa-arrows-rotate me-1"></i>Refresh
                </button>
            </div>
            <div id="clustersTableWrap">
            <?php if (empty($activeClusters)): ?>
                <p class="ptmd-muted small">No active clusters found.</p>
            <?php else: ?>
            <div class="table-responsive">
            <table class="ptmd-table table table-dark table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Label</th>
                        <th>Trend Score</th>
                        <th>Risk Level</th>
                        <th>Signals</th>
                        <th>Status</th>
                        <th>Shelf Life</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($activeClusters as $cluster): ?>
                    <tr>
                        <td class="fw-500"><?php ee($cluster['label'] ?? '—'); ?></td>
                        <td>
                            <?php
                            $ts       = (float) ($cluster['trend_score'] ?? 0);
                            $barColor = $ts >= 75 ? 'bg-success' : ($ts >= 50 ? 'bg-warning' : 'bg-danger');
                            ?>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height:6px;min-width:60px">
                                    <div class="progress-bar <?php echo $barColor; ?>" style="width:<?php echo min(100, $ts); ?>%"></div>
                                </div>
                                <span class="badge <?php echo $barColor; ?> text-dark" style="font-size:0.7rem"><?php echo number_format($ts, 1); ?></span>
                            </div>
                        </td>
                        <td>
                            <?php
                            $risk   = strtolower($cluster['risk_level'] ?? 'low');
                            $riskBg = match($risk) {
                                'high', 'critical' => 'bg-danger',
                                'medium'           => 'bg-warning text-dark',
                                default            => 'bg-secondary',
                            };
                            ?>
                            <span class="badge <?php echo $riskBg; ?>"><?php ee(ucfirst($risk)); ?></span>
                        </td>
                        <td class="ptmd-muted"><?php echo (int) ($cluster['signal_count'] ?? 0); ?></td>
                        <td>
                            <span class="badge bg-info text-dark"><?php ee($cluster['status'] ?? 'active'); ?></span>
                        </td>
                        <td class="ptmd-muted small">
                            <?php
                            $exp = $cluster['expires_at'] ?? null;
                            echo $exp ? e(date('M j, Y', strtotime($exp))) : '—';
                            ?>
                        </td>
                        <td>
                            <button class="btn btn-ptmd-primary btn-sm promote-cluster-btn"
                                    data-cluster-id="<?php echo (int) $cluster['id']; ?>"
                                    data-label="<?php ee($cluster['label'] ?? ''); ?>">
                                <i class="fa-solid fa-arrow-up-right-dots me-1"></i>Promote
                            </button>
                            <a href="#recentSignals" class="btn btn-ptmd-ghost btn-sm ms-1">
                                <i class="fa-solid fa-eye me-1"></i>Signals
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
            </div><!-- /#clustersTableWrap -->
        </div>
    </div>

    <!-- Ingest New Signal form -->
    <div class="col-lg-4">
        <div class="ptmd-panel p-lg">
            <h5 class="mb-3"><i class="fa-solid fa-plus-circle me-2 ptmd-text-teal"></i>Ingest New Signal</h5>
            <div id="ingestAlert"></div>
            <form id="ingestSignalForm" novalidate>
                <div class="mb-3">
                    <label class="form-label small ptmd-muted">Topic <span class="text-danger">*</span></label>
                    <input type="text" name="normalized_topic" class="form-control" placeholder="e.g. hospital billing fraud" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small ptmd-muted">Source</label>
                    <select name="source_id" class="form-select">
                        <option value="">Manual Entry</option>
                        <?php foreach ($sources as $src): ?>
                            <option value="<?php echo (int) $src['id']; ?>"><?php ee($src['display_name'] ?? $src['name'] ?? ''); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php
                $rangeFields = [
                    ['freshness_score',     'Freshness Score'],
                    ['cultural_score',      'Cultural Score'],
                    ['brand_fit_score',     'Brand Fit'],
                    ['sensitivity_score',   'Sensitivity Score'],
                    ['doc_potential_score', 'Documentary Potential'],
                ];
                foreach ($rangeFields as [$fname, $flabel]):
                ?>
                <div class="mb-3">
                    <label class="form-label small ptmd-muted d-flex justify-content-between">
                        <span><?php echo $flabel; ?></span>
                        <span class="range-val ptmd-text-teal fw-bold" id="val_<?php echo $fname; ?>">50</span>
                    </label>
                    <input type="range" name="<?php echo $fname; ?>" id="<?php echo $fname; ?>"
                           class="form-range trend-range" min="0" max="100" value="50">
                </div>
                <?php endforeach; ?>
                <div class="mb-3">
                    <label class="form-label small ptmd-muted">Explanation</label>
                    <textarea name="explanation_text" class="form-control" rows="3" placeholder="Why is this trend relevant?"></textarea>
                </div>
                <button type="submit" class="btn btn-ptmd-primary w-100" id="ingestSubmitBtn">
                    <i class="fa-solid fa-satellite-dish me-2"></i>Ingest Signal
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Recent Signals table -->
<div id="recentSignals" class="ptmd-panel p-lg mb-4">
    <h5 class="mb-3"><i class="fa-solid fa-list-ul me-2 ptmd-text-teal"></i>Recent Signals</h5>
    <?php if (empty($recentSignals)): ?>
        <p class="ptmd-muted small">No signals yet.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table class="ptmd-table table table-dark table-hover align-middle mb-0">
        <thead>
            <tr>
                <th>Topic</th>
                <th>Trend Score</th>
                <th>Status</th>
                <th>Source</th>
                <th>Cluster</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recentSignals as $sig): ?>
            <tr>
                <td class="fw-500"><?php ee($sig['normalized_topic'] ?? '—'); ?></td>
                <td>
                    <?php
                    $sc = (float) ($sig['trend_score'] ?? 0);
                    $sb = $sc >= 75 ? 'bg-success' : ($sc >= 50 ? 'bg-warning text-dark' : 'bg-secondary');
                    ?>
                    <span class="badge <?php echo $sb; ?>"><?php echo number_format($sc, 1); ?></span>
                </td>
                <td>
                    <?php
                    $st   = $sig['status'] ?? 'raw';
                    $stBg = match($st) {
                        'promoted'           => 'bg-success',
                        'clustered'          => 'bg-info text-dark',
                        'expired', 'rejected'=> 'bg-danger',
                        default              => 'bg-secondary',
                    };
                    ?>
                    <span class="badge <?php echo $stBg; ?>"><?php ee($st); ?></span>
                </td>
                <td class="ptmd-muted small"><?php ee($sig['source_id'] ? '#' . (int) $sig['source_id'] : 'manual'); ?></td>
                <td class="ptmd-muted small"><?php ee($sig['cluster_id'] ? '#' . (int) $sig['cluster_id'] : '—'); ?></td>
                <td class="ptmd-muted small"><?php echo $sig['created_at'] ? e(date('M j, H:i', strtotime($sig['created_at']))) : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php
$extraScripts = '<script type="module" src="/assets/js/admin/trends.js"></script>';
include __DIR__ . '/_admin_footer.php';
?>

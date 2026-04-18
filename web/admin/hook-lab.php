<?php
/**
 * PTMD Admin — Hook Lab
 */

$pageTitle      = 'Hook Lab | PTMD Admin';
$activePage     = 'hook-lab';
$pageHeading    = 'Hook Lab';
$pageSubheading = 'Generate, score, and approve attention-hook copy across platforms.';

include __DIR__ . '/_admin_head.php';

require_once __DIR__ . '/../inc/services/HookService.php';

$pdo = get_db();

$labSummary  = [];
$cases       = [];
$recentHooks = [];
$hookStats   = ['total' => 0, 'approved' => 0, 'avg_retention' => 0];

try {
    $labSummary = ptmd_hook_lab_summary(null, null, 30);
} catch (\Throwable $e) {}

if ($pdo) {
    try {
        $cases = $pdo->query(
            'SELECT id, title FROM cases ORDER BY title'
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}

    try {
        $recentHooks = $pdo->query(
            'SELECT h.*, c.title AS case_title
             FROM hooks h
             LEFT JOIN cases c ON c.id = h.case_id
             ORDER BY h.created_at DESC
             LIMIT 30'
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}

    try {
        $sr = $pdo->query(
            'SELECT
                COUNT(*) AS total,
                SUM(approved = 1) AS approved,
                AVG(expected_retention_score) AS avg_retention
             FROM hooks'
        )->fetch(\PDO::FETCH_ASSOC);
        if ($sr) {
            $hookStats = [
                'total'         => (int) ($sr['total'] ?? 0),
                'approved'      => (int) ($sr['approved'] ?? 0),
                'avg_retention' => round((float) ($sr['avg_retention'] ?? 0), 1),
            ];
        }
    } catch (\Throwable $e) {}
}

$hookTypeOptions = [
    'direct_question'        => 'Direct Question',
    'bold_claim'             => 'Bold Claim',
    'shocking_stat'          => 'Shocking Stat',
    'personal_stake'         => 'Personal Stake',
    'mystery_reveal'         => 'Mystery Reveal',
    'false_belief_correction'=> 'False Belief Correction',
    'countdown_urgency'      => 'Countdown / Urgency',
    'exclusive_access'       => 'Exclusive Access',
    'enemy_reveal'           => 'Enemy Reveal',
    'contrast_surprise'      => 'Contrast / Surprise',
    'micro_story'            => 'Micro Story',
    'empathy_hook'           => 'Empathy Hook',
    'transformation_promise' => 'Transformation Promise',
    'pattern_interrupt'      => 'Pattern Interrupt',
    'relatable_frustration'  => 'Relatable Frustration',
    'proof_by_authority'     => 'Proof by Authority',
];

$topHookType = '';
if (!empty($labSummary)) {
    usort($labSummary, fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));
    $topHookType = $labSummary[0]['hook_type'] ?? '';
}
?>
<meta name="csrf-token" content="<?php echo csrf_token(); ?>">

<!-- KPI Row -->
<div class="row g-4 mb-5">
    <div class="col-6 col-lg-3">
        <div class="ptmd-panel p-3 text-center">
            <div class="ptmd-text-teal fw-bold" style="font-size:2rem"><?php echo $hookStats['total']; ?></div>
            <div class="ptmd-muted small mt-1"><i class="fa-solid fa-anchor me-1"></i>Total Hooks</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ptmd-panel p-3 text-center">
            <div class="fw-bold" style="font-size:2rem;color:var(--bs-success)"><?php echo $hookStats['approved']; ?></div>
            <div class="ptmd-muted small mt-1"><i class="fa-solid fa-check-circle me-1"></i>Approved Hooks</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ptmd-panel p-3 text-center">
            <div class="fw-bold" style="font-size:2rem;color:var(--bs-info)"><?php echo $hookStats['avg_retention']; ?>%</div>
            <div class="ptmd-muted small mt-1"><i class="fa-solid fa-eye me-1"></i>Avg Expected Retention</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ptmd-panel p-3 text-center">
            <div class="fw-bold ptmd-text-teal" style="font-size:1.1rem;padding-top:0.4rem"><?php ee($hookTypeOptions[$topHookType] ?? $topHookType ?: '—'); ?></div>
            <div class="ptmd-muted small mt-1"><i class="fa-solid fa-trophy me-1"></i>Top Hook Type</div>
        </div>
    </div>
</div>

<!-- Three-panel layout -->
<div class="row g-4 mb-5">

    <!-- LEFT: Generate Hooks form -->
    <div class="col-lg-4">
        <div class="ptmd-panel p-lg mb-4">
            <h5 class="mb-3"><i class="fa-solid fa-wand-magic-sparkles me-2 ptmd-text-teal"></i>Generate Hooks</h5>
            <form id="hookGenerateForm" novalidate>
                <div class="mb-3">
                    <label class="form-label small ptmd-muted">Case <span class="text-danger">*</span></label>
                    <select name="case_id" class="form-select" required>
                        <option value="">— Select Case —</option>
                        <?php foreach ($cases as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>"><?php ee($c['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
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
                <div class="mb-3">
                    <label class="form-label small ptmd-muted">Hook Type</label>
                    <select name="hook_type" class="form-select">
                        <option value="auto">Auto (AI Selects Best)</option>
                        <?php foreach ($hookTypeOptions as $val => $label): ?>
                            <option value="<?php echo $val; ?>"><?php ee($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-ptmd-primary w-100" id="hookGenerateBtn">
                    <i class="fa-solid fa-bolt me-2"></i>Generate Hooks
                </button>
            </form>
        </div>
        <!-- Hook Results -->
        <div id="hookResults" style="display:none">
            <div class="ptmd-panel p-lg">
                <h6 class="ptmd-muted mb-3">Generated Hooks</h6>
                <div id="hookResultsBody"></div>
            </div>
        </div>
    </div>

    <!-- CENTER: Hook Performance Table -->
    <div class="col-lg-5">
        <div class="ptmd-panel p-lg">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><i class="fa-solid fa-table-list me-2 ptmd-text-teal"></i>Hook Performance</h5>
            </div>
            <!-- Filter bar -->
            <div class="row g-2 mb-3">
                <div class="col-4">
                    <select id="filterPlatform" class="form-select form-select-sm">
                        <option value="">All Platforms</option>
                        <option value="youtube">YouTube</option>
                        <option value="tiktok">TikTok</option>
                        <option value="instagram">Instagram</option>
                        <option value="facebook">Facebook</option>
                        <option value="x">X</option>
                    </select>
                </div>
                <div class="col-5">
                    <select id="filterHookType" class="form-select form-select-sm">
                        <option value="">All Types</option>
                        <?php foreach ($hookTypeOptions as $val => $label): ?>
                            <option value="<?php echo $val; ?>"><?php ee($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-3">
                    <select id="filterStatus" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <option value="1">Approved</option>
                        <option value="0">Pending</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
            </div>
            <div id="hookTableWrap">
            <?php if (empty($recentHooks)): ?>
                <p class="ptmd-muted small">No hooks yet.</p>
            <?php else: ?>
            <div class="table-responsive">
            <table class="ptmd-table table table-dark table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Hook</th>
                        <th>Type</th>
                        <th>Retention</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentHooks as $hook): ?>
                    <tr style="cursor:pointer" class="hook-row" data-hook-id="<?php echo (int) $hook['id']; ?>">
                        <td>
                            <div class="fw-500 small" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                <?php ee(mb_strimwidth($hook['hook_text'] ?? '—', 0, 60, '…')); ?>
                            </div>
                            <div class="ptmd-text-dim" style="font-size:0.7rem"><?php ee($hook['case_title'] ?? ''); ?></div>
                        </td>
                        <td>
                            <span class="badge bg-secondary" style="font-size:0.65rem"><?php ee($hook['hook_type'] ?? '—'); ?></span>
                        </td>
                        <td>
                            <?php $ret = (float) ($hook['expected_retention_score'] ?? 0); $retBg = $ret >= 70 ? 'bg-success' : ($ret >= 40 ? 'bg-warning text-dark' : 'bg-danger'); ?>
                            <span class="badge <?php echo $retBg; ?>" style="font-size:0.7rem"><?php echo number_format($ret, 1); ?>%</span>
                        </td>
                        <td>
                            <?php
                            $approved = $hook['approved'] ?? null;
                            $status   = $hook['status'] ?? null;
                            if ($approved == 1 || $status === 'approved') {
                                echo '<span class="badge bg-success">Approved</span>';
                            } elseif ($status === 'rejected') {
                                echo '<span class="badge bg-danger">Rejected</span>';
                            } else {
                                echo '<span class="badge bg-secondary">Pending</span>';
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
            </div><!-- /#hookTableWrap -->
        </div>
    </div>

    <!-- RIGHT: Hook Type Performance Summary -->
    <div class="col-lg-3">
        <div class="ptmd-panel p-lg">
            <h5 class="mb-3"><i class="fa-solid fa-chart-bar me-2 ptmd-text-teal"></i>Type Performance</h5>
            <?php if (empty($labSummary)): ?>
                <p class="ptmd-muted small">No data yet.</p>
            <?php else: ?>
            <div class="table-responsive">
            <table class="ptmd-table table table-dark align-middle mb-0" style="font-size:0.8rem">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Count</th>
                        <th>Avg Ret.</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($labSummary as $row): ?>
                    <tr>
                        <td class="ptmd-muted" style="max-width:90px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <?php ee($hookTypeOptions[$row['hook_type'] ?? ''] ?? ($row['hook_type'] ?? '—')); ?>
                        </td>
                        <td><?php echo (int) ($row['count'] ?? 0); ?></td>
                        <td>
                            <?php $avg = (float) ($row['avg_retention'] ?? 0); $avgBg = $avg >= 70 ? 'text-success' : ($avg >= 40 ? 'text-warning' : 'text-danger'); ?>
                            <span class="<?php echo $avgBg; ?> fw-bold"><?php echo number_format($avg, 1); ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Selected Hook Detail Panel (initially hidden) -->
<div id="hookDetailPanel" class="ptmd-panel p-lg mb-4" style="display:none">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="mb-0"><i class="fa-solid fa-magnifying-glass me-2 ptmd-text-teal"></i>Hook Detail</h5>
        <button class="btn btn-ptmd-ghost btn-sm" id="hookDetailCloseBtn">
            <i class="fa-solid fa-xmark me-1"></i>Close
        </button>
    </div>
    <div class="row g-4">
        <div class="col-lg-7">
            <p id="hookDetailText" class="mb-3" style="font-size:1.1rem;line-height:1.7"></p>
            <div class="d-flex flex-wrap gap-2 mb-4" id="hookDetailMeta"></div>
            <!-- Score breakdowns -->
            <h6 class="ptmd-muted mb-3">Score Breakdown</h6>
            <div id="hookDetailScores"></div>
        </div>
        <div class="col-lg-5">
            <div class="d-flex gap-2 flex-wrap mb-3" id="hookDetailActions">
                <button class="btn btn-ptmd-primary" id="hookApproveBtn" data-hook-id="">
                    <i class="fa-solid fa-check me-2"></i>Approve
                </button>
                <button class="btn btn-ptmd-danger" id="hookRejectBtn" data-hook-id="">
                    <i class="fa-solid fa-xmark me-2"></i>Reject
                </button>
                <button class="btn btn-ptmd-secondary" id="hookDuplicateBtn" data-hook-id="">
                    <i class="fa-solid fa-copy me-2"></i>Duplicate
                </button>
            </div>
        </div>
    </div>
</div>

<?php
$extraScripts = '<script type="module" src="/assets/js/admin/hook-lab.js"></script>';
include __DIR__ . '/_admin_footer.php';
?>

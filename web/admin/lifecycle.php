<?php
/**
 * PTMD Admin — Content Lifecycle
 */

$pageTitle      = 'Lifecycle | PTMD Admin';
$activePage     = 'lifecycle';
$pageHeading    = 'Content Lifecycle';
$pageSubheading = 'Visualise the content pipeline, manage approvals, and transition cases.';

include __DIR__ . '/_admin_head.php';

require_once __DIR__ . '/../inc/services/LifecycleService.php';

$pdo = get_db();

$statusCounts      = [];
$pendingApprovals  = [];
$staleItems        = [];
$recentTransitions = [];
$allCases          = [];

if ($pdo) {
    try {
        $rows = $pdo->query(
            'SELECT status, COUNT(*) AS cnt FROM cases GROUP BY status'
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $statusCounts[$row['status']] = (int) $row['cnt'];
        }
    } catch (\Throwable $e) {}

    try {
        $pendingApprovals = $pdo->query(
            'SELECT ea.*, u.username AS requested_by_name
             FROM editorial_approvals ea
             LEFT JOIN users u ON u.id = ea.requested_by
             WHERE ea.status = \'pending\'
             ORDER BY ea.created_at DESC
             LIMIT 20'
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}

    try {
        $recentTransitions = $pdo->query(
            'SELECT cst.*, u.username AS actor_name
             FROM content_state_transitions cst
             LEFT JOIN users u ON u.id = cst.actor_id
             ORDER BY cst.created_at DESC
             LIMIT 50'
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}

    try {
        $allCases = $pdo->query(
            'SELECT id, title, status FROM cases ORDER BY title'
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}

try {
    $staleItems = ptmd_lifecycle_stale_entities('case', 7);
} catch (\Throwable $e) {}

$lifecycleStates = [
    'idea', 'scored', 'shortlisted', 'promoted_to_case',
    'researching', 'scripting', 'recording', 'editing',
    'clipping', 'optimized', 'awaiting_approval',
    'approved', 'scheduled', 'published',
];
?>
<meta name="csrf-token" content="<?php echo csrf_token(); ?>">

<!-- Pipeline Visual -->
<div class="ptmd-panel p-lg mb-5">
    <h5 class="mb-3"><i class="fa-solid fa-diagram-next me-2 ptmd-text-teal"></i>Pipeline Overview</h5>
    <div class="d-flex overflow-auto pb-2 gap-0" style="min-width:100%">
        <?php foreach ($lifecycleStates as $i => $state): ?>
            <?php if ($i > 0): ?>
                <div class="d-flex align-items-center px-1" style="color:var(--bs-secondary)">
                    <i class="fa-solid fa-chevron-right" style="font-size:0.65rem"></i>
                </div>
            <?php endif; ?>
            <?php
            $count  = $statusCounts[$state] ?? 0;
            $isCurrent = $count > 0;
            $bgStyle = $isCurrent ? 'background:rgba(0,210,200,0.13);border:1px solid rgba(0,210,200,0.4)' : 'background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08)';
            ?>
            <div class="text-center rounded px-2 py-2 flex-shrink-0" style="min-width:90px;<?php echo $bgStyle; ?>">
                <div class="<?php echo $isCurrent ? 'ptmd-text-teal fw-bold' : 'ptmd-muted'; ?>" style="font-size:1.2rem;line-height:1"><?php echo $count; ?></div>
                <div class="ptmd-text-dim mt-1" style="font-size:0.65rem;text-transform:uppercase;letter-spacing:0.03em;word-break:break-word">
                    <?php echo str_replace('_', ' ', $state); ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Two-column layout -->
<div class="row g-4 mb-5">

    <!-- LEFT: Pending Approvals + Stale Items -->
    <div class="col-lg-7">

        <!-- Pending Approvals -->
        <div class="ptmd-panel p-lg mb-4">
            <h5 class="mb-3"><i class="fa-solid fa-hourglass-half me-2" style="color:var(--bs-warning)"></i>Pending Approvals</h5>
            <?php if (empty($pendingApprovals)): ?>
                <p class="ptmd-muted small">No pending approvals.</p>
            <?php else: ?>
            <div class="table-responsive">
            <table class="ptmd-table table table-dark table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Entity</th>
                        <th>Requested By</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingApprovals as $ap): ?>
                    <tr>
                        <td class="ptmd-muted small">#<?php echo (int) $ap['id']; ?></td>
                        <td>
                            <span class="badge bg-secondary me-1"><?php ee($ap['entity_type'] ?? ''); ?></span>
                            #<?php echo (int) ($ap['entity_id'] ?? 0); ?>
                        </td>
                        <td class="ptmd-muted small"><?php ee($ap['requested_by_name'] ?? '—'); ?></td>
                        <td class="ptmd-muted small"><?php echo $ap['created_at'] ? e(date('M j, H:i', strtotime($ap['created_at']))) : '—'; ?></td>
                        <td>
                            <button class="btn btn-ptmd-primary btn-sm approve-action-btn"
                                    data-approval-id="<?php echo (int) $ap['id']; ?>"
                                    data-entity-type="<?php ee($ap['entity_type'] ?? ''); ?>"
                                    data-entity-id="<?php echo (int) ($ap['entity_id'] ?? 0); ?>">
                                <i class="fa-solid fa-check me-1"></i>Approve
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Stale Items -->
        <div class="ptmd-panel p-lg">
            <h5 class="mb-3"><i class="fa-solid fa-clock me-2" style="color:var(--bs-danger)"></i>Stale Items (&gt;7 days)</h5>
            <?php if (empty($staleItems)): ?>
                <p class="ptmd-muted small">No stale items found.</p>
            <?php else: ?>
            <div class="table-responsive">
            <table class="ptmd-table table table-dark table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Entity</th>
                        <th>Status</th>
                        <th>Stale Since</th>
                        <th>Days</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($staleItems as $item): ?>
                    <tr>
                        <td>
                            <span class="badge bg-secondary me-1"><?php ee($item['entity_type'] ?? 'case'); ?></span>
                            <?php ee($item['title'] ?? ('#' . (int) ($item['id'] ?? 0))); ?>
                        </td>
                        <td><span class="badge bg-warning text-dark"><?php ee($item['status'] ?? '—'); ?></span></td>
                        <td class="ptmd-muted small"><?php echo !empty($item['last_updated']) ? e(date('M j, Y', strtotime($item['last_updated']))) : '—'; ?></td>
                        <td>
                            <?php $days = (int) ($item['days_stale'] ?? 0); ?>
                            <span class="badge bg-danger"><?php echo $days; ?>d</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- RIGHT: Transition a Case -->
    <div class="col-lg-5">
        <div class="ptmd-panel p-lg">
            <h5 class="mb-3"><i class="fa-solid fa-right-left me-2 ptmd-text-teal"></i>Transition a Case</h5>
            <form id="transitionForm" novalidate>
                <div class="mb-3">
                    <label class="form-label small ptmd-muted">Case <span class="text-danger">*</span></label>
                    <select name="case_id" class="form-select" required>
                        <option value="">— Select Case —</option>
                        <?php foreach ($allCases as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>" data-status="<?php ee($c['status'] ?? ''); ?>">
                                <?php ee($c['title']); ?>
                                <span class="ptmd-muted">(<?php ee($c['status'] ?? ''); ?>)</span>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small ptmd-muted">New State <span class="text-danger">*</span></label>
                    <select name="new_state" class="form-select" required>
                        <option value="">— Select State —</option>
                        <?php foreach ($lifecycleStates as $state): ?>
                            <option value="<?php echo $state; ?>"><?php echo ucfirst(str_replace('_', ' ', $state)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small ptmd-muted">Reason / Note</label>
                    <textarea name="reason" class="form-control" rows="3" placeholder="Optional reason for this transition…"></textarea>
                </div>
                <button type="submit" class="btn btn-ptmd-primary w-100" id="transitionSubmitBtn">
                    <i class="fa-solid fa-right-left me-2"></i>Apply Transition
                </button>
            </form>
        </div>
    </div>

</div>

<!-- Recent Transitions Feed -->
<div class="ptmd-panel p-lg mb-4">
    <h5 class="mb-3"><i class="fa-solid fa-timeline me-2 ptmd-text-teal"></i>Recent Transitions</h5>
    <div id="recentTransitionsFeed">
    <?php if (empty($recentTransitions)): ?>
        <p class="ptmd-muted small">No transitions recorded yet.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table class="ptmd-table table table-dark table-hover align-middle mb-0">
        <thead>
            <tr>
                <th>Entity</th>
                <th>From</th>
                <th>To</th>
                <th>Actor</th>
                <th>Reason</th>
                <th>At</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recentTransitions as $t): ?>
            <tr>
                <td>
                    <span class="badge bg-secondary me-1"><?php ee($t['entity_type'] ?? 'case'); ?></span>
                    #<?php echo (int) ($t['entity_id'] ?? 0); ?>
                </td>
                <td><span class="badge bg-secondary"><?php ee($t['from_state'] ?? '—'); ?></span></td>
                <td><span class="badge bg-info text-dark"><?php ee($t['to_state'] ?? '—'); ?></span></td>
                <td class="ptmd-muted small"><?php ee($t['actor_name'] ?? 'system'); ?></td>
                <td class="ptmd-muted small" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?php ee($t['reason'] ?? '—'); ?>
                </td>
                <td class="ptmd-muted small"><?php echo $t['created_at'] ? e(date('M j, H:i', strtotime($t['created_at']))) : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    </div><!-- /#recentTransitionsFeed -->
</div>

<?php
$extraScripts = '<script type="module" src="/assets/js/admin/lifecycle.js"></script>';
include __DIR__ . '/_admin_footer.php';
?>

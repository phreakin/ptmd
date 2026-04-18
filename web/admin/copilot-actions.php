<?php
/**
 * PTMD Admin — Copilot Actions
 */

$pageTitle      = 'Copilot Actions | PTMD Admin';
$activePage     = 'copilot-actions';
$pageHeading    = 'Copilot Actions';
$pageSubheading = 'Review, approve, and execute AI-suggested actions from the assistant.';

include __DIR__ . '/_admin_head.php';

$pdo = get_db();

$pendingActions = [];
$recentLogs     = [];
$actionStats    = ['suggested' => 0, 'pending_approval' => 0, 'approved' => 0, 'executed' => 0, 'rejected' => 0];

if ($pdo) {
    try {
        $pendingActions = $pdo->query(
            'SELECT aaa.*, s.title AS session_title, u.username AS session_username
             FROM ai_assistant_actions aaa
             LEFT JOIN ai_assistant_sessions s ON s.id = aaa.session_id
             LEFT JOIN users u ON u.id = s.user_id
             WHERE aaa.status IN (\'suggested\', \'pending_approval\')
             ORDER BY aaa.created_at DESC
             LIMIT 20'
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}

    try {
        $recentLogs = $pdo->query(
            'SELECT aal.*, aaa.action_type, aaa.target_table, u.username AS performed_by_name
             FROM ai_assistant_action_logs aal
             LEFT JOIN ai_assistant_actions aaa ON aaa.id = aal.action_id
             LEFT JOIN users u ON u.id = aal.performed_by
             ORDER BY aal.created_at DESC
             LIMIT 50'
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}

    try {
        $rows = $pdo->query(
            'SELECT status, COUNT(*) AS cnt FROM ai_assistant_actions GROUP BY status'
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $actionStats[$row['status']] = (int) $row['cnt'];
        }
    } catch (\Throwable $e) {}
}

$pending  = $actionStats['suggested'] + $actionStats['pending_approval'];
$approved = $actionStats['approved'] ?? 0;
$executed = $actionStats['executed'] ?? 0;
$rejected = $actionStats['rejected'] ?? 0;
?>
<meta name="csrf-token" content="<?php echo csrf_token(); ?>">

<!-- Stats Row -->
<div class="row g-4 mb-5">
    <div class="col-6 col-lg-3">
        <div class="ptmd-panel p-3 text-center">
            <div class="fw-bold" style="font-size:2rem;color:var(--bs-warning)"><?php echo $pending; ?></div>
            <div class="ptmd-muted small mt-1"><i class="fa-solid fa-hourglass-half me-1"></i>Pending</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ptmd-panel p-3 text-center">
            <div class="fw-bold" style="font-size:2rem;color:var(--bs-success)"><?php echo $approved; ?></div>
            <div class="ptmd-muted small mt-1"><i class="fa-solid fa-check-circle me-1"></i>Approved</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ptmd-panel p-3 text-center">
            <div class="ptmd-text-teal fw-bold" style="font-size:2rem"><?php echo $executed; ?></div>
            <div class="ptmd-muted small mt-1"><i class="fa-solid fa-bolt me-1"></i>Executed</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ptmd-panel p-3 text-center">
            <div class="fw-bold" style="font-size:2rem;color:var(--bs-danger)"><?php echo $rejected; ?></div>
            <div class="ptmd-muted small mt-1"><i class="fa-solid fa-xmark me-1"></i>Rejected</div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="ptmd-panel p-3 mb-4">
    <div class="row g-2 align-items-center">
        <div class="col-auto">
            <span class="ptmd-muted small"><i class="fa-solid fa-filter me-1"></i>Filter:</span>
        </div>
        <div class="col-12 col-sm-3">
            <select id="filterStatus" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <option value="suggested">Suggested</option>
                <option value="pending_approval">Pending Approval</option>
                <option value="approved">Approved</option>
                <option value="executed">Executed</option>
                <option value="rejected">Rejected</option>
            </select>
        </div>
        <div class="col-12 col-sm-3">
            <select id="filterActionType" class="form-select form-select-sm">
                <option value="">All Action Types</option>
                <option value="update_field">Update Field</option>
                <option value="insert_record">Insert Record</option>
                <option value="delete_record">Delete Record</option>
                <option value="run_optimizer">Run Optimizer</option>
                <option value="promote_case">Promote Case</option>
                <option value="transition_state">Transition State</option>
            </select>
        </div>
        <div class="col-12 col-sm-3">
            <select id="filterRiskLevel" class="form-select form-select-sm">
                <option value="">All Risk Levels</option>
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
                <option value="critical">Critical</option>
            </select>
        </div>
    </div>
</div>

<!-- Pending Actions Table -->
<div class="ptmd-panel p-lg mb-5">
    <h5 class="mb-3"><i class="fa-solid fa-robot me-2 ptmd-text-teal"></i>Pending Actions</h5>
    <div id="pendingActionsTable">
    <?php if (empty($pendingActions)): ?>
        <p class="ptmd-muted small">No pending actions.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table class="ptmd-table table table-dark table-hover align-middle mb-0">
        <thead>
            <tr>
                <th>Action Type</th>
                <th>Target</th>
                <th>Risk</th>
                <th>Session</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($pendingActions as $action): ?>
            <?php
            $risk      = strtolower($action['risk_level'] ?? 'low');
            $isHighRisk = in_array($risk, ['high', 'critical'], true);
            $rowClass  = $isHighRisk ? 'table-danger' : '';
            $riskBg    = match($risk) {
                'critical' => 'bg-danger',
                'high'     => 'bg-danger',
                'medium'   => 'bg-warning text-dark',
                default    => 'bg-secondary',
            };
            ?>
            <tr class="<?php echo $rowClass; ?>">
                <td>
                    <span class="fw-500"><?php ee($action['action_type'] ?? '—'); ?></span>
                    <?php if ($isHighRisk): ?>
                        <i class="fa-solid fa-triangle-exclamation text-warning ms-1" title="High risk action"></i>
                    <?php endif; ?>
                </td>
                <td class="ptmd-muted small">
                    <?php if (!empty($action['target_table'])): ?>
                        <span class="badge bg-secondary me-1"><?php ee($action['target_table']); ?></span>
                    <?php endif; ?>
                    <?php ee($action['target_id'] ? '#' . (int) $action['target_id'] : '—'); ?>
                </td>
                <td>
                    <span class="badge <?php echo $riskBg; ?>"><?php ee(ucfirst($risk)); ?></span>
                </td>
                <td class="ptmd-muted small">
                    <?php ee($action['session_username'] ?? '—'); ?>
                    <?php if (!empty($action['session_title'])): ?>
                        <div class="ptmd-text-dim" style="font-size:0.7rem"><?php ee(mb_strimwidth($action['session_title'], 0, 30, '…')); ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge bg-warning text-dark"><?php ee($action['status'] ?? ''); ?></span>
                </td>
                <td class="ptmd-muted small"><?php echo $action['created_at'] ? e(date('M j, H:i', strtotime($action['created_at']))) : '—'; ?></td>
                <td>
                    <div class="d-flex gap-1 flex-wrap">
                        <button class="btn btn-ptmd-primary btn-sm approve-action-btn"
                                data-action-id="<?php echo (int) $action['id']; ?>"
                                data-risk="<?php ee($risk); ?>">
                            <i class="fa-solid fa-check me-1"></i>Approve
                        </button>
                        <button class="btn btn-ptmd-danger btn-sm reject-action-btn"
                                data-action-id="<?php echo (int) $action['id']; ?>">
                            <i class="fa-solid fa-xmark me-1"></i>Reject
                        </button>
                        <button class="btn btn-ptmd-secondary btn-sm execute-action-btn"
                                data-action-id="<?php echo (int) $action['id']; ?>"
                                data-risk="<?php ee($risk); ?>">
                            <i class="fa-solid fa-bolt me-1"></i>Execute
                        </button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    </div><!-- /#pendingActionsTable -->
</div>

<!-- Execution Log -->
<div class="ptmd-panel p-lg mb-4">
    <h5 class="mb-3"><i class="fa-solid fa-scroll me-2 ptmd-text-teal"></i>Execution Log</h5>
    <?php if (empty($recentLogs)): ?>
        <p class="ptmd-muted small">No execution logs yet.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table class="ptmd-table table table-dark table-hover align-middle mb-0">
        <thead>
            <tr>
                <th>Action Type</th>
                <th>Target</th>
                <th>Performed By</th>
                <th>Status</th>
                <th>Result</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recentLogs as $log): ?>
            <tr>
                <td class="fw-500"><?php ee($log['action_type'] ?? '—'); ?></td>
                <td class="ptmd-muted small">
                    <?php if (!empty($log['target_table'])): ?>
                        <span class="badge bg-secondary me-1"><?php ee($log['target_table']); ?></span>
                    <?php endif; ?>
                    <?php ee(!empty($log['target_id']) ? '#' . (int) $log['target_id'] : '—'); ?>
                </td>
                <td class="ptmd-muted small"><?php ee($log['performed_by_name'] ?? 'system'); ?></td>
                <td>
                    <?php
                    $logStatus = $log['status'] ?? '';
                    $logBg     = match($logStatus) {
                        'success'  => 'bg-success',
                        'failed'   => 'bg-danger',
                        'skipped'  => 'bg-secondary',
                        default    => 'bg-secondary',
                    };
                    ?>
                    <span class="badge <?php echo $logBg; ?>"><?php ee($logStatus ?: '—'); ?></span>
                </td>
                <td class="ptmd-muted small" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?php ee(mb_strimwidth($log['result_message'] ?? $log['result'] ?? '—', 0, 60, '…')); ?>
                </td>
                <td class="ptmd-muted small"><?php echo $log['created_at'] ? e(date('M j, H:i', strtotime($log['created_at']))) : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php
$extraScripts = '<script type="module" src="/assets/js/admin/copilot-actions.js"></script>';
include __DIR__ . '/_admin_footer.php';
?>

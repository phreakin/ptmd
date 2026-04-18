<?php
/**
 * PTMD API v1 — Cases
 *
 * GET  ?action=workflow&id=N      Full case workflow bundle.
 * GET  ?action=list&status=X      List cases by status.
 * GET  ?action=blueprints         List active blueprints.
 * POST {"action":"apply_blueprint"}   Apply a blueprint to a case.
 * POST {"action":"create_from_trend"} Promote a trend cluster to a case.
 *
 * Requires admin session.
 */

require_once __DIR__ . '/../../inc/bootstrap.php';
require_once __DIR__ . '/../../inc/services/LifecycleService.php';
require_once __DIR__ . '/../../inc/services/TrendIntakeService.php';
require_once __DIR__ . '/../../inc/services/EventTrackingService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'data' => null, 'error' => 'Unauthorized']);
    exit;
}

$pdo    = get_db();
$admin  = current_admin();
$userId = (int) ($admin['id'] ?? 0);

if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'data' => null, 'error' => 'Database unavailable']);
    exit;
}

$method  = $_SERVER['REQUEST_METHOD'];
$traceId = ptmd_generate_trace_id();

// ── GET ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? '');

    if ($action === 'workflow') {
        $caseId = (int) ($_GET['id'] ?? 0);
        if ($caseId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'id required', 'trace_id' => $traceId]);
            exit;
        }

        // Case row
        $cStmt = $pdo->prepare('SELECT * FROM cases WHERE id = :id LIMIT 1');
        $cStmt->execute([':id' => $caseId]);
        $case = $cStmt->fetch();
        if (!$case) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'data' => null, 'error' => "Case #{$caseId} not found", 'trace_id' => $traceId]);
            exit;
        }

        // Lifecycle state + history
        $lifecycleState   = ptmd_lifecycle_current_state('case', $caseId);
        $lifecycleHistory = ptmd_lifecycle_history('case', $caseId, 20);

        // Pending approvals
        $apStmt = $pdo->prepare(
            "SELECT * FROM editorial_approvals WHERE entity_type = 'case' AND entity_id = :id AND status = 'pending'"
        );
        $apStmt->execute([':id' => $caseId]);
        $pendingApprovals = $apStmt->fetchAll();

        // Hooks
        $hStmt = $pdo->prepare("SELECT * FROM hooks WHERE case_id = :id ORDER BY created_at DESC");
        $hStmt->execute([':id' => $caseId]);
        $hooks = $hStmt->fetchAll();

        // Optimizer runs (last 3)
        $orStmt = $pdo->prepare(
            "SELECT * FROM optimizer_runs WHERE target_type = 'case' AND target_id = :id ORDER BY created_at DESC LIMIT 3"
        );
        $orStmt->execute([':id' => $caseId]);
        $optimizerRuns = $orStmt->fetchAll();

        // Active queue items
        $qStmt = $pdo->prepare(
            "SELECT * FROM social_post_queue WHERE case_id = :id AND status NOT IN ('sent','failed') ORDER BY scheduled_at ASC"
        );
        $qStmt->execute([':id' => $caseId]);
        $queueItems = $qStmt->fetchAll();

        echo json_encode([
            'ok'   => true,
            'data' => [
                'case'              => $case,
                'lifecycle_state'   => $lifecycleState,
                'lifecycle_history' => $lifecycleHistory,
                'pending_approvals' => $pendingApprovals,
                'hooks'             => $hooks,
                'optimizer_runs'    => $optimizerRuns,
                'queue_items'       => $queueItems,
            ],
            'error'    => null,
            'trace_id' => $traceId,
        ]);
        exit;
    }

    if ($action === 'list') {
        $status  = (string) ($_GET['status']   ?? '');
        $page    = max(1, (int) ($_GET['page']     ?? 1));
        $perPage = min((int) ($_GET['per_page'] ?? 50), 100);
        $offset  = ($page - 1) * $perPage;

        $sql    = 'SELECT * FROM cases';
        $params = [];
        if ($status !== '') {
            $sql .= ' WHERE status = :status';
            $params[':status'] = $status;
        }
        $sql .= ' ORDER BY updated_at DESC LIMIT :limit OFFSET :offset';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();

        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(), 'error' => null, 'trace_id' => $traceId]);
        exit;
    }

    if ($action === 'blueprints') {
        $stmt = $pdo->query("SELECT * FROM case_blueprints WHERE status = 'active' ORDER BY name ASC");
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(), 'error' => null, 'trace_id' => $traceId]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'data' => null, 'error' => 'Unknown action', 'trace_id' => $traceId]);
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!verify_csrf($csrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'data' => null, 'error' => 'Invalid CSRF token', 'trace_id' => $traceId]);
        exit;
    }

    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = (string) ($body['action'] ?? $_POST['action'] ?? '');

    if ($action === 'apply_blueprint') {
        $caseId      = (int) ($body['case_id']      ?? 0);
        $blueprintId = (int) ($body['blueprint_id'] ?? 0);

        if ($caseId <= 0 || $blueprintId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'case_id and blueprint_id required', 'trace_id' => $traceId]);
            exit;
        }

        $bpStmt = $pdo->prepare("SELECT * FROM case_blueprints WHERE id = :id AND status = 'active' LIMIT 1");
        $bpStmt->execute([':id' => $blueprintId]);
        $blueprint = $bpStmt->fetch();
        if (!$blueprint) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'data' => null, 'error' => "Blueprint #{$blueprintId} not found", 'trace_id' => $traceId]);
            exit;
        }

        // Apply blueprint fields to case (only non-null overrideable columns)
        $fields  = [];
        $allowed = ['content_type', 'platform_targets', 'default_hook_type', 'production_notes'];
        foreach ($allowed as $col) {
            if (!empty($blueprint[$col])) {
                $fields[$col] = $blueprint[$col];
            }
        }

        if (!empty($fields)) {
            $setClauses = implode(', ', array_map(fn($k) => "{$k} = :{$k}", array_keys($fields)));
            $upd        = $pdo->prepare("UPDATE cases SET {$setClauses}, updated_at = NOW() WHERE id = :case_id");
            $bindParams = [':case_id' => $caseId];
            foreach ($fields as $col => $val) {
                $bindParams[':' . $col] = $val;
            }
            $upd->execute($bindParams);
        }

        ptmd_emit_event('case.blueprint_applied', 'cases', 'case', $caseId,
            ['blueprint_id' => $blueprintId], $userId, $traceId, null, null, null, null, 'ok', 'human');

        echo json_encode([
            'ok'   => true,
            'data' => ['case_id' => $caseId, 'blueprint_id' => $blueprintId, 'applied_fields' => array_keys($fields)],
            'error'    => null,
            'trace_id' => $traceId,
        ]);
        exit;
    }

    if ($action === 'create_from_trend') {
        $clusterId = (int) ($body['cluster_id'] ?? 0);
        if ($clusterId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'cluster_id required', 'trace_id' => $traceId]);
            exit;
        }

        $result = ptmd_trend_promote_to_case($clusterId, $userId);
        if (!($result['ok'] ?? false)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'data' => null, 'error' => $result['error'] ?? 'Failed', 'trace_id' => $traceId]);
            exit;
        }

        ptmd_emit_event('case.created_from_trend', 'cases', 'case', $result['case_id'] ?? null,
            ['cluster_id' => $clusterId], $userId, $traceId, null, null, null, null, 'ok', 'human');

        echo json_encode(['ok' => true, 'data' => $result, 'error' => null, 'trace_id' => $traceId]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'data' => null, 'error' => 'Unknown action', 'trace_id' => $traceId]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'data' => null, 'error' => 'Method not allowed']);
exit;

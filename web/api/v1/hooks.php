<?php
/**
 * PTMD API v1 — Hooks
 *
 * GET  ?action=list           List hooks for a case/platform.
 * GET  ?action=lab_summary    Hook Lab aggregate summary.
 * POST {"action":"generate"}  Generate new hooks via AI.
 * POST {"action":"approve"}   Approve a hook.
 * POST {"action":"reject"}    Reject a hook.
 * POST {"action":"record_performance"} Record post-publish metrics.
 *
 * Requires admin session.
 */

require_once __DIR__ . '/../../inc/bootstrap.php';
require_once __DIR__ . '/../../inc/services/HookService.php';
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
    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'list':
            $caseId   = (int)    ($_GET['case_id']  ?? 0);
            $platform = (string) ($_GET['platform'] ?? '');
            if ($caseId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'case_id required', 'trace_id' => $traceId]);
                exit;
            }
            $hooks = ptmd_hook_get_for_case($caseId, $platform ?: null);
            echo json_encode(['ok' => true, 'data' => $hooks, 'error' => null, 'trace_id' => $traceId]);
            exit;

        case 'lab_summary':
            $platform = (string) ($_GET['platform']  ?? '');
            $hookType = (string) ($_GET['hook_type'] ?? '');
            $days     = max(1, (int) ($_GET['days'] ?? 30));
            $summary  = ptmd_hook_lab_summary($platform ?: null, $hookType ?: null, $days);
            echo json_encode(['ok' => true, 'data' => $summary, 'error' => null, 'trace_id' => $traceId]);
            exit;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'Unknown action', 'trace_id' => $traceId]);
            exit;
    }
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

    switch ($action) {
        case 'generate':
            $caseId   = (int)    ($body['case_id']   ?? 0);
            $platform = (string) ($body['platform']  ?? 'all');
            $hookType = (string) ($body['hook_type'] ?? '') ?: null;

            if ($caseId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'case_id required', 'trace_id' => $traceId]);
                exit;
            }

            $result = ptmd_hook_generate($caseId, $platform, $hookType, [], $userId);
            if (!$result['ok']) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'data' => null, 'error' => $result['error'], 'trace_id' => $traceId]);
                exit;
            }

            ptmd_emit_event('hook.generated', 'hooks', 'case', $caseId,
                ['platform' => $platform, 'hook_type' => $hookType, 'count' => count($result['hooks'] ?? [])],
                $userId, $traceId, null, null, null, null, 'ok', 'ai');

            echo json_encode(['ok' => true, 'data' => $result, 'error' => null, 'trace_id' => $traceId]);
            exit;

        case 'approve':
        case 'reject':
            $hookId   = (int) ($body['hook_id'] ?? 0);
            $approved = ($action === 'approve');

            if ($hookId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'hook_id required', 'trace_id' => $traceId]);
                exit;
            }

            $result = ptmd_hook_approve($hookId, $approved, $userId);
            if (!($result['ok'] ?? false)) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'data' => null, 'error' => $result['error'] ?? 'Failed', 'trace_id' => $traceId]);
                exit;
            }

            ptmd_emit_event(
                $approved ? 'hook.approved' : 'hook.rejected',
                'hooks', 'hook', $hookId, [], $userId, $traceId, null, null, null, null, 'ok', 'human'
            );

            echo json_encode(['ok' => true, 'data' => $result, 'error' => null, 'trace_id' => $traceId]);
            exit;

        case 'record_performance':
            $hookId   = (int)   ($body['hook_id']  ?? 0);
            $queueId  = (int)   ($body['queue_id'] ?? 0);
            $platform = (string)($body['platform'] ?? '');
            $metrics  = (array) ($body['metrics']  ?? []);

            if ($hookId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'hook_id required', 'trace_id' => $traceId]);
                exit;
            }

            $result = ptmd_hook_record_performance($hookId, $queueId ?: null, $platform, $metrics, $userId);
            if (!($result['ok'] ?? false)) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'data' => null, 'error' => $result['error'] ?? 'Failed', 'trace_id' => $traceId]);
                exit;
            }

            ptmd_emit_event('hook.performance_recorded', 'hooks', 'hook', $hookId,
                array_merge($metrics, ['platform' => $platform, 'queue_id' => $queueId]),
                $userId, $traceId, null, null, null, null, 'ok', 'human');

            echo json_encode(['ok' => true, 'data' => $result, 'error' => null, 'trace_id' => $traceId]);
            exit;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'Unknown action', 'trace_id' => $traceId]);
            exit;
    }
}

http_response_code(405);
echo json_encode(['ok' => false, 'data' => null, 'error' => 'Method not allowed']);
exit;

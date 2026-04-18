<?php
/**
 * PTMD API v1 — AI Optimizer
 *
 * GET  ?action=get_run     Retrieve an optimizer run by ID.
 * GET  ?action=explain     Get plain-language explanation for a run.
 * POST {"action":"run"}    Trigger an optimizer run.
 * POST {"action":"review_variant"} Accept or reject a variant.
 *
 * Requires admin session.
 */

require_once __DIR__ . '/../../inc/bootstrap.php';
require_once __DIR__ . '/../../inc/services/OptimizerService.php';
require_once __DIR__ . '/../../inc/services/AuditExplainabilityService.php';
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
    $runId  = (int) ($_GET['run_id'] ?? 0);

    switch ($action) {
        case 'get_run':
            if ($runId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'run_id required', 'trace_id' => $traceId]);
                exit;
            }
            $run = ptmd_optimizer_get_run($runId);
            if (!$run) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'data' => null, 'error' => "Run #{$runId} not found", 'trace_id' => $traceId]);
                exit;
            }
            echo json_encode(['ok' => true, 'data' => $run, 'error' => null, 'trace_id' => $traceId]);
            exit;

        case 'explain':
            if ($runId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'run_id required', 'trace_id' => $traceId]);
                exit;
            }
            $explanation = ptmd_explain_optimizer_run($runId);
            echo json_encode(['ok' => true, 'data' => ['explanation' => $explanation], 'error' => null, 'trace_id' => $traceId]);
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

    if ($action === 'run') {
        $targetType = (string) ($body['target_type'] ?? '');
        $targetId   = (int)    ($body['target_id']   ?? 0);
        $platform   = (string) ($body['platform']    ?? 'all');
        $cohort     = (string) ($body['cohort']       ?? 'general');
        $context    = (array)  ($body['context']      ?? []);

        if ($targetType === '' || $targetId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'target_type and target_id required', 'trace_id' => $traceId]);
            exit;
        }

        $result = ptmd_optimizer_run($targetType, $targetId, $platform, $cohort, $context, $userId);

        if (!$result['ok']) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'data' => null, 'error' => $result['error'], 'trace_id' => $traceId]);
            exit;
        }

        $explanation = $result['run_id'] ? ptmd_explain_optimizer_run($result['run_id']) : null;

        ptmd_emit_event('optimizer.run', 'optimizer', $targetType, $targetId,
            ['run_id' => $result['run_id'], 'platform' => $platform, 'score' => $result['score'] ?? null],
            $userId, $traceId, null, null, null, $result['confidence'] ?? null, 'ok', 'human');

        echo json_encode([
            'ok'          => true,
            'data'        => array_merge($result, ['explanation' => $explanation]),
            'error'       => null,
            'trace_id'    => $traceId,
        ]);
        exit;
    }

    if ($action === 'review_variant') {
        $variantId       = (int)    ($body['variant_id']       ?? 0);
        $accepted        = (bool)   ($body['accepted']         ?? false);
        $rejectionReason = (string) ($body['rejection_reason'] ?? '');

        if ($variantId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'variant_id required', 'trace_id' => $traceId]);
            exit;
        }

        $result = ptmd_optimizer_review_variant($variantId, $accepted, $rejectionReason, $userId);

        if (!$result['ok']) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'data' => null, 'error' => $result['error'], 'trace_id' => $traceId]);
            exit;
        }

        ptmd_emit_event(
            $accepted ? 'optimizer.variant.accepted' : 'optimizer.variant.rejected',
            'optimizer', 'optimizer_variant', $variantId,
            ['rejection_reason' => $rejectionReason],
            $userId, $traceId, null, null, null, null, 'ok', 'human'
        );

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

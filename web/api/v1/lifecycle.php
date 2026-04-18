<?php
/**
 * PTMD API v1 — Content Lifecycle State Machine
 *
 * GET  ?action=current_state  Returns current state for an entity.
 * GET  ?action=history        Returns transition history for an entity.
 * GET  ?action=stale          Returns stale entities past a threshold.
 * GET  ?action=in_state       Returns entities in a given state.
 * POST {"action":"transition"} Records a state transition.
 *
 * Requires admin session.
 */

require_once __DIR__ . '/../../inc/bootstrap.php';
require_once __DIR__ . '/../../inc/services/LifecycleService.php';
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
    $action     = $_GET['action'] ?? '';
    $entityType = (string) ($_GET['entity_type'] ?? '');
    $entityId   = (int) ($_GET['entity_id']   ?? 0);
    $limit      = min((int) ($_GET['limit'] ?? 50), 100);

    switch ($action) {
        case 'current_state':
            if ($entityType === '' || $entityId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'entity_type and entity_id required', 'trace_id' => $traceId]);
                exit;
            }
            $state = ptmd_lifecycle_current_state($entityType, $entityId);
            echo json_encode(['ok' => true, 'data' => ['state' => $state], 'error' => null, 'trace_id' => $traceId]);
            exit;

        case 'history':
            if ($entityType === '' || $entityId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'entity_type and entity_id required', 'trace_id' => $traceId]);
                exit;
            }
            $history = ptmd_lifecycle_history($entityType, $entityId, $limit);
            echo json_encode(['ok' => true, 'data' => $history, 'error' => null, 'trace_id' => $traceId]);
            exit;

        case 'stale':
            if ($entityType === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'entity_type required', 'trace_id' => $traceId]);
                exit;
            }
            $days   = max(1, (int) ($_GET['days'] ?? 7));
            $stale  = ptmd_lifecycle_stale_entities($entityType, $days);
            echo json_encode(['ok' => true, 'data' => $stale, 'error' => null, 'trace_id' => $traceId]);
            exit;

        case 'in_state':
            if ($entityType === '' || ($state = (string) ($_GET['state'] ?? '')) === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'entity_type and state required', 'trace_id' => $traceId]);
                exit;
            }
            $entities = ptmd_lifecycle_entities_in_state($entityType, $state, $limit);
            echo json_encode(['ok' => true, 'data' => $entities, 'error' => null, 'trace_id' => $traceId]);
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

    if ($action === 'transition') {
        $entityType = (string) ($body['entity_type'] ?? '');
        $entityId   = (int)    ($body['entity_id']   ?? 0);
        $toState    = (string) ($body['to_state']     ?? '');
        $reason     = (string) ($body['reason']       ?? '');
        $actorType  = (string) ($body['actor_type']   ?? 'human');

        if ($entityType === '' || $entityId <= 0 || $toState === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'entity_type, entity_id, and to_state required', 'trace_id' => $traceId]);
            exit;
        }

        $result = ptmd_lifecycle_transition($entityType, $entityId, $toState, $actorType, $userId, $reason, [], $traceId);

        if (!$result['ok']) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'data' => null, 'error' => $result['error'], 'trace_id' => $traceId]);
            exit;
        }

        $fromState = ptmd_lifecycle_current_state($entityType, $entityId);
        echo json_encode([
            'ok'       => true,
            'data'     => [
                'transition_id' => $result['transition_id'],
                'from_state'    => $fromState,
                'to_state'      => $toState,
            ],
            'error'    => null,
            'trace_id' => $traceId,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'data' => null, 'error' => 'Unknown action', 'trace_id' => $traceId]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'data' => null, 'error' => 'Method not allowed']);
exit;

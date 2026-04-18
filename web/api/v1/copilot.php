<?php
/**
 * PTMD API v1 — AI Copilot
 *
 * GET  ?action=sessions           List recent sessions.
 * GET  ?action=get_session        Return session messages.
 * POST {"action":"chat"}          Send a message to the copilot.
 * POST {"action":"create_session"} Create a new session.
 * POST {"action":"execute_action"} Execute a suggested action.
 * POST {"action":"pin_message"}   Pin a message in a session.
 * POST {"action":"approve_action"} Update action decision.
 *
 * Requires admin session.
 */

require_once __DIR__ . '/../../inc/bootstrap.php';
require_once __DIR__ . '/../../inc/services/CopilotOrchestratorService.php';
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

    if ($action === 'sessions') {
        $stmt = $pdo->prepare(
            'SELECT id, title, updated_at FROM ai_assistant_sessions
              WHERE user_id = :uid
              ORDER BY updated_at DESC
              LIMIT 20'
        );
        $stmt->execute([':uid' => $userId]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(), 'error' => null, 'trace_id' => $traceId]);
        exit;
    }

    if ($action === 'get_session') {
        $sessionId = (int) ($_GET['session_id'] ?? 0);
        if ($sessionId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'session_id required', 'trace_id' => $traceId]);
            exit;
        }
        $session = ptmd_copilot_get_session($sessionId, $userId);
        if (!$session) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'data' => null, 'error' => "Session #{$sessionId} not found", 'trace_id' => $traceId]);
            exit;
        }
        echo json_encode(['ok' => true, 'data' => $session, 'error' => null, 'trace_id' => $traceId]);
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

    switch ($action) {
        case 'create_session':
            $title = trim((string) ($body['title'] ?? 'New Session'));
            $stmt  = $pdo->prepare(
                'INSERT INTO ai_assistant_sessions (user_id, title, created_at, updated_at)
                 VALUES (:uid, :title, NOW(), NOW())'
            );
            $stmt->execute([':uid' => $userId, ':title' => $title]);
            $sessionId = (int) $pdo->lastInsertId();

            ptmd_emit_event('copilot.session.created', 'copilot', 'session', $sessionId,
                ['title' => $title], $userId, $traceId, null, null, null, null, 'ok', 'human');

            echo json_encode(['ok' => true, 'data' => ['session_id' => $sessionId, 'title' => $title], 'error' => null, 'trace_id' => $traceId]);
            exit;

        case 'chat':
            $message      = trim((string) ($body['message']       ?? ''));
            $sessionId    = (int)    ($body['session_id']    ?? 0);
            $scope        = (string) ($body['scope']         ?? 'all');
            $contextObjId = (int)    ($body['context_obj_id'] ?? 0) ?: null;
            $mode         = (string) ($body['mode']          ?? 'ask');

            if ($message === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'message required', 'trace_id' => $traceId]);
                exit;
            }

            // Auto-create session if not provided
            if ($sessionId <= 0) {
                $titleText = mb_substr($message, 0, 60) . (mb_strlen($message) > 60 ? '…' : '');
                $ins = $pdo->prepare(
                    'INSERT INTO ai_assistant_sessions (user_id, title, created_at, updated_at)
                     VALUES (:uid, :title, NOW(), NOW())'
                );
                $ins->execute([':uid' => $userId, ':title' => $titleText]);
                $sessionId = (int) $pdo->lastInsertId();
            }

            $result = ptmd_copilot_chat($sessionId, $message, $scope, $contextObjId, $userId, $mode);
            if (!$result['ok']) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'data' => null, 'error' => $result['error'], 'trace_id' => $traceId]);
                exit;
            }

            ptmd_emit_event('copilot.message.sent', 'copilot', 'session', $sessionId,
                ['mode' => $mode, 'scope' => $scope], $userId, $traceId, (string) $sessionId,
                null, null, null, 'ok', 'human');

            echo json_encode([
                'ok'       => true,
                'data'     => [
                    'session_id'       => $sessionId,
                    'message_id'       => $result['message_id'],
                    'reply'            => $result['reply'],
                    'refs'             => $result['refs']    ?? [],
                    'suggested_actions'=> $result['actions'] ?? [],
                    'explanation'      => $result['explanation'] ?? [],
                ],
                'error'    => null,
                'trace_id' => $traceId,
            ]);
            exit;

        case 'execute_action':
            $actionId = (int)  ($body['action_id'] ?? 0);
            $confirm  = (bool) ($body['confirm']   ?? false);

            if ($actionId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'action_id required', 'trace_id' => $traceId]);
                exit;
            }

            // Risk gate: fetch risk level before executing
            $aStmt = $pdo->prepare('SELECT risk_level FROM ai_assistant_actions WHERE id = :id LIMIT 1');
            $aStmt->execute([':id' => $actionId]);
            $actionRow = $aStmt->fetch();
            if ($actionRow) {
                $riskLevel = (string) ($actionRow['risk_level'] ?? 'low');
                if (in_array($riskLevel, ['high', 'critical'], true) && !$confirm) {
                    echo json_encode([
                        'ok'       => false,
                        'data'     => ['requires_confirm' => true, 'risk_level' => $riskLevel],
                        'error'    => "Action has risk_level '{$riskLevel}'. Send confirm=true to proceed.",
                        'trace_id' => $traceId,
                    ]);
                    exit;
                }
            }

            $result = ptmd_copilot_execute_action($actionId, $userId);
            if (!($result['ok'] ?? false)) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'data' => null, 'error' => $result['error'] ?? 'Execution failed', 'trace_id' => $traceId]);
                exit;
            }

            ptmd_emit_event('copilot.action.executed', 'copilot', 'ai_assistant_action', $actionId,
                [], $userId, $traceId, null, null, null, null, 'ok', 'human');

            echo json_encode(['ok' => true, 'data' => $result, 'error' => null, 'trace_id' => $traceId]);
            exit;

        case 'pin_message':
            $messageId = (int) ($body['message_id'] ?? 0);
            if ($messageId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'message_id required', 'trace_id' => $traceId]);
                exit;
            }

            $result = ptmd_copilot_pin_message($messageId, $userId);
            if (!($result['ok'] ?? false)) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'data' => null, 'error' => $result['error'] ?? 'Failed', 'trace_id' => $traceId]);
                exit;
            }

            ptmd_emit_event('copilot.message.pinned', 'copilot', 'ai_assistant_message', $messageId,
                [], $userId, $traceId, null, null, null, null, 'ok', 'human');

            echo json_encode(['ok' => true, 'data' => $result, 'error' => null, 'trace_id' => $traceId]);
            exit;

        case 'approve_action':
            $actionId = (int)    ($body['action_id'] ?? 0);
            $decision = (string) ($body['decision']  ?? '');

            if ($actionId <= 0 || $decision === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'action_id and decision required', 'trace_id' => $traceId]);
                exit;
            }

            $validDecisions = ['approved', 'rejected', 'deferred'];
            if (!in_array($decision, $validDecisions, true)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'decision must be approved|rejected|deferred', 'trace_id' => $traceId]);
                exit;
            }

            $upd = $pdo->prepare(
                'UPDATE ai_assistant_actions SET status = :status, reviewed_by = :uid, reviewed_at = NOW()
                  WHERE id = :id'
            );
            $upd->execute([':status' => $decision, ':uid' => $userId, ':id' => $actionId]);

            ptmd_emit_event('copilot.action.reviewed', 'copilot', 'ai_assistant_action', $actionId,
                ['decision' => $decision], $userId, $traceId, null, null, $decision, null, 'ok', 'human');

            echo json_encode(['ok' => true, 'data' => ['action_id' => $actionId, 'status' => $decision], 'error' => null, 'trace_id' => $traceId]);
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

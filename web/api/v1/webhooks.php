<?php
/**
 * PTMD API v1 — Webhooks
 *
 * POST ?type=inbound&source=trend_push  Inbound webhook with HMAC validation.
 * GET  ?action=list_deliveries          List webhook deliveries.
 * POST {"action":"retry","delivery_id"} Retry a failed delivery.
 *
 * Inbound: no session required — validated by HMAC signature.
 * Management: requires admin session.
 */

require_once __DIR__ . '/../../inc/bootstrap.php';
require_once __DIR__ . '/../../inc/services/TrendIntakeService.php';
require_once __DIR__ . '/../../inc/services/EventTrackingService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pdo     = get_db();
$method  = $_SERVER['REQUEST_METHOD'];
$traceId = ptmd_generate_trace_id();

// ── INBOUND WEBHOOK ───────────────────────────────────────────────────────────
if ($method === 'POST' && ($_GET['type'] ?? '') === 'inbound') {
    $rawBody = file_get_contents('php://input');
    $source  = (string) ($_GET['source'] ?? '');

    // HMAC signature validation
    $secret    = site_setting('webhook_secret', '');
    $expected  = hash_hmac('sha256', $rawBody, $secret);
    $provided  = $_SERVER['HTTP_X_PTMD_SIGNATURE'] ?? '';

    if ($secret === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'data' => null, 'error' => 'Invalid signature', 'trace_id' => $traceId]);
        exit;
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'data' => null, 'error' => 'Invalid JSON payload', 'trace_id' => $traceId]);
        exit;
    }

    $deliveryId  = null;
    $status      = 'processed';
    $errorDetail = null;

    if ($source === 'trend_push') {
        $result = ptmd_trend_ingest($payload);
        if (!$result['ok']) {
            $status      = 'failed';
            $errorDetail = $result['error'];
        }
    } else {
        $status      = 'ignored';
        $errorDetail = "Unknown source: {$source}";
    }

    // Log to webhook_deliveries
    if ($pdo) {
        try {
            $ins = $pdo->prepare(
                'INSERT INTO webhook_deliveries
                     (source, event_type, payload, status, error_detail, received_at, next_attempt_at)
                 VALUES
                     (:source, :event_type, :payload, :status, :error_detail, NOW(), NULL)'
            );
            $ins->execute([
                ':source'       => $source,
                ':event_type'   => (string) ($payload['event_type'] ?? 'unknown'),
                ':payload'      => $rawBody,
                ':status'       => $status,
                ':error_detail' => $errorDetail,
            ]);
            $deliveryId = (int) $pdo->lastInsertId();
        } catch (\Throwable $e) {
            error_log('[PTMD Webhook] Failed to log delivery: ' . $e->getMessage());
        }

        ptmd_emit_event('webhook.received', 'system', 'webhook_delivery', $deliveryId,
            ['source' => $source, 'status' => $status],
            null, $traceId, null, null, null, null, $status, 'api');
    }

    $httpCode = $status === 'failed' ? 422 : 200;
    http_response_code($httpCode);
    echo json_encode([
        'ok'          => $status !== 'failed',
        'data'        => ['delivery_id' => $deliveryId, 'status' => $status],
        'error'       => $errorDetail,
        'trace_id'    => $traceId,
    ]);
    exit;
}

// ── Management routes — require admin auth ────────────────────────────────────
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'data' => null, 'error' => 'Unauthorized']);
    exit;
}

if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'data' => null, 'error' => 'Database unavailable']);
    exit;
}

$admin  = current_admin();
$userId = (int) ($admin['id'] ?? 0);

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? '');

    if ($action === 'list_deliveries') {
        $status  = (string) ($_GET['status']   ?? '');
        $limit   = min((int) ($_GET['limit']    ?? 20), 100);
        $page    = max(1, (int) ($_GET['page']  ?? 1));
        $offset  = ($page - 1) * $limit;

        $sql    = 'SELECT * FROM webhook_deliveries';
        $params = [];
        if ($status !== '') {
            $sql .= ' WHERE status = :status';
            $params[':status'] = $status;
        }
        $sql .= ' ORDER BY received_at DESC LIMIT :limit OFFSET :offset';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  $limit,  \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(), 'error' => null, 'trace_id' => $traceId]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'data' => null, 'error' => 'Unknown action', 'trace_id' => $traceId]);
    exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!verify_csrf($csrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'data' => null, 'error' => 'Invalid CSRF token', 'trace_id' => $traceId]);
        exit;
    }

    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = (string) ($body['action'] ?? $_POST['action'] ?? '');

    if ($action === 'retry') {
        $deliveryId = (int) ($body['delivery_id'] ?? 0);
        if ($deliveryId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'delivery_id required', 'trace_id' => $traceId]);
            exit;
        }

        $chk = $pdo->prepare("SELECT id, status FROM webhook_deliveries WHERE id = :id LIMIT 1");
        $chk->execute([':id' => $deliveryId]);
        $delivery = $chk->fetch();
        if (!$delivery) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'data' => null, 'error' => "Delivery #{$deliveryId} not found", 'trace_id' => $traceId]);
            exit;
        }

        $upd = $pdo->prepare(
            "UPDATE webhook_deliveries
             SET status = 'pending', next_attempt_at = DATE_ADD(NOW(), INTERVAL 1 MINUTE), error_detail = NULL
             WHERE id = :id"
        );
        $upd->execute([':id' => $deliveryId]);

        ptmd_emit_event('webhook.retry_queued', 'system', 'webhook_delivery', $deliveryId,
            [], $userId, $traceId, null, $delivery['status'], 'pending', null, 'ok', 'human');

        echo json_encode([
            'ok'       => true,
            'data'     => ['delivery_id' => $deliveryId, 'status' => 'pending'],
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

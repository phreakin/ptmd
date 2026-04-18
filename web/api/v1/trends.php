<?php
/**
 * PTMD API v1 — Trends
 *
 * GET  ?action=list_clusters  List active trend clusters.
 * POST {"action":"ingest"}    Ingest a new trend signal.
 * POST {"action":"cluster"}   Group signals into a cluster.
 * POST {"action":"promote"}   Promote cluster to a case.
 *
 * Requires admin session.
 */

require_once __DIR__ . '/../../inc/bootstrap.php';
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
    $action = $_GET['action'] ?? '';

    if ($action === 'list_clusters') {
        $limit    = min((int) ($_GET['limit'] ?? 20), 100);
        $clusters = ptmd_trend_get_active_clusters($limit);
        echo json_encode(['ok' => true, 'data' => $clusters, 'error' => null, 'trace_id' => $traceId]);
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
        case 'ingest':
            if (empty($body['normalized_topic'])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'normalized_topic required', 'trace_id' => $traceId]);
                exit;
            }

            $result = ptmd_trend_ingest($body, $userId);
            if (!$result['ok']) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'data' => null, 'error' => $result['error'], 'trace_id' => $traceId]);
                exit;
            }

            ptmd_emit_event('trend.ingested', 'trends', 'trend_signal', $result['signal_id'],
                ['topic' => $body['normalized_topic'], 'duplicate' => $result['duplicate']],
                $userId, $traceId, null, null, null, null, 'ok', 'human');

            echo json_encode(['ok' => true, 'data' => $result, 'error' => null, 'trace_id' => $traceId]);
            exit;

        case 'cluster':
            $signalIds = (array)  ($body['signal_ids'] ?? []);
            $label     = (string) ($body['label']      ?? '');
            $summary   = (string) ($body['summary']    ?? '');

            if (empty($signalIds) || $label === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'signal_ids and label required', 'trace_id' => $traceId]);
                exit;
            }

            $result = ptmd_trend_cluster_signals(array_map('intval', $signalIds), $label, $summary, $userId);
            if (!($result['ok'] ?? false)) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'data' => null, 'error' => $result['error'] ?? 'Failed', 'trace_id' => $traceId]);
                exit;
            }

            ptmd_emit_event('trend.clustered', 'trends', 'trend_cluster', $result['cluster_id'] ?? null,
                ['label' => $label, 'signal_count' => count($signalIds)],
                $userId, $traceId, null, null, null, null, 'ok', 'human');

            echo json_encode(['ok' => true, 'data' => $result, 'error' => null, 'trace_id' => $traceId]);
            exit;

        case 'promote':
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

            ptmd_emit_event('trend.promoted_to_case', 'trends', 'trend_cluster', $clusterId,
                ['case_id' => $result['case_id'] ?? null],
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

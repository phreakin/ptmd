<?php
/**
 * PTMD API — Trigger Analytics Sync
 *
 * Admin-authenticated POST endpoint.
 * Runs run_social_metrics_sync() which:
 *  - Fetches external platform metrics for all posted queue items
 *  - Rolls up today's raw events into site_analytics_daily
 *  - Records the run in analytics_sync_runs
 *
 * POST { csrf_token: "..." }
 *
 * Returns JSON: { ok, synced, failed, skipped, error? }
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/analytics.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$pdo = get_db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
    exit;
}

$result = run_social_metrics_sync($pdo);

echo json_encode([
    'ok'      => !isset($result['error']),
    'synced'  => $result['synced'],
    'failed'  => $result['failed'],
    'skipped' => $result['skipped'],
    'error'   => $result['error'] ?? null,
]);

<?php
/**
 * PTMD API — Social Dispatch Worker
 *
 * Runs queued/scheduled social posts that are due, for cron automation.
 * Authentication:
 * - Admin session OR
 * - X-PTMD-Worker-Token header (or ?token=) matching site_settings.automation_worker_token
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/content_workflow.php';

header('Content-Type: application/json; charset=utf-8');

$configuredToken = trim(site_setting('automation_worker_token', ''));
$providedToken = trim((string) ($_SERVER['HTTP_X_PTMD_WORKER_TOKEN'] ?? ($_GET['token'] ?? '')));
$isAuthorizedWorker = $configuredToken !== '' && hash_equals($configuredToken, $providedToken);

if (!is_logged_in() && !$isAuthorizedWorker) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$limit = max(1, min(100, (int) ($_GET['limit'] ?? $_POST['limit'] ?? 25)));
$result = ptmd_process_due_social_queue(null, $limit);

if (empty($result['ok'])) {
    http_response_code(503);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);

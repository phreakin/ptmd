<?php
/**
 * PTMD — Scheduler API Endpoint  (api/scheduler.php)
 *
 * Invoked by an external cron job.  NOT meant for browser access.
 *
 * Authentication: Bearer token in Authorization header
 *   Authorization: Bearer <scheduler_secret>
 *
 * Optional query params:
 *   ?dry_run=1   — simulate a run without writing to DB or calling social APIs
 *
 * Responses: JSON  { ok: bool, ... }
 *
 * Security controls:
 *  - Token must match site_settings.scheduler_secret (non-empty)
 *  - Optional IP allowlist via site_settings.scheduler_ip_allowlist
 *  - scheduler_enabled must be "1" in site_settings
 *  - Lock/lease prevents concurrent runs
 */

declare(strict_types=1);

// No session needed for this endpoint; bootstrap brings DB + functions.
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/social_services.php';
require_once __DIR__ . '/../inc/scheduler.php';

header('Content-Type: application/json; charset=utf-8');
// Prevent browsers/proxies from caching scheduler responses
header('Cache-Control: no-store');

// ── Helper: JSON exit ─────────────────────────────────────────────────────────

function scheduler_respond(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── 1. Only allow GET or POST ─────────────────────────────────────────────────

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    scheduler_respond(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

// ── 2. Extract Bearer token ───────────────────────────────────────────────────

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
// Some CGI/FastCGI setups expose REDIRECT_HTTP_AUTHORIZATION instead
if ($authHeader === '') {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
}

$token = '';
if (str_starts_with($authHeader, 'Bearer ')) {
    $token = substr($authHeader, 7);
}

// ── 3. Verify token ───────────────────────────────────────────────────────────

if (!scheduler_verify_token($token)) {
    scheduler_respond(['ok' => false, 'error' => 'Unauthorized. Provide a valid Bearer token.'], 401);
}

// ── 4. Verify caller IP (optional allowlist) ──────────────────────────────────

$callerIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (!scheduler_verify_ip($callerIp)) {
    scheduler_respond(['ok' => false, 'error' => 'Forbidden. Caller IP not in allowlist.'], 403);
}

// ── 5. Check scheduler_enabled flag ──────────────────────────────────────────

$pdo = get_db();
if (!$pdo) {
    scheduler_respond(['ok' => false, 'error' => 'Database unavailable.'], 503);
}

$enabled = _scheduler_setting($pdo, 'scheduler_enabled', '1');
if ($enabled !== '1') {
    scheduler_respond(['ok' => false, 'error' => 'Scheduler is disabled in site settings.'], 503);
}

// ── 6. Parse options ──────────────────────────────────────────────────────────

$dryRun = ($_GET['dry_run'] ?? $_POST['dry_run'] ?? '0') === '1';

// ── 7. Run scheduler ──────────────────────────────────────────────────────────

$result = scheduler_run($pdo, $dryRun);

error_log(
    '[PTMD Scheduler] ' .
    ($dryRun ? 'DRY RUN ' : '') .
    'generated=' . ($result['total_generated'] ?? 0) . ' ' .
    'dispatched=' . ($result['total_dispatched'] ?? 0) . ' ' .
    'errors=' . ($result['total_errors'] ?? 0)
);

scheduler_respond($result, ($result['ok'] ?? false) ? 200 : 207);

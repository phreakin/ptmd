<?php
/**
 * PTMD API — Edit Jobs Background Worker
 *
 * Processes all pending edit_job_outputs until completion (or limits).
 * Designed to be called:
 *   - From a server cron: php web/api/process_edit_jobs.php
 *   - Via HTTP by an admin (session auth)
 *   - Via HTTP with ?token=AUTOMATION_TOKEN (site_settings: automation_worker_token)
 *
 * CLI: php process_edit_jobs.php [--max-outputs=N] [--max-jobs=N]
 */

// ── CLI detection ─────────────────────────────────────────────────────────────
$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/video_processor.php';

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!$isCli) {
    $authed = false;

    // Admin session
    if (is_logged_in()) {
        $authed = true;
    }

    // Automation token
    if (!$authed) {
        $providedToken = trim((string) ($_GET['token'] ?? $_SERVER['HTTP_X_WORKER_TOKEN'] ?? ''));
        if ($providedToken !== '') {
            $configuredToken = site_setting('automation_worker_token', '');
            if ($configuredToken !== '' && hash_equals($configuredToken, $providedToken)) {
                $authed = true;
            }
        }
    }

    if (!$authed) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

// ── Parse options ─────────────────────────────────────────────────────────────
if ($isCli) {
    $maxOutputs = 200;
    $maxJobs    = 0;
    foreach (array_slice($argv ?? [], 1) as $arg) {
        if (preg_match('/^--max-outputs=(\d+)$/', $arg, $m)) {
            $maxOutputs = max(1, (int) $m[1]);
        }
        if (preg_match('/^--max-jobs=(\d+)$/', $arg, $m)) {
            $maxJobs = max(0, (int) $m[1]);
        }
    }
} else {
    $maxOutputs = max(1, min(500, (int) ($_GET['max_outputs'] ?? 200)));
    $maxJobs    = max(0, min(50,  (int) ($_GET['max_jobs']    ?? 0)));
}

// ── Run worker ────────────────────────────────────────────────────────────────
$pdo = get_db();
if (!$pdo) {
    if ($isCli) {
        fwrite(STDERR, "[PTMD Worker] Database unavailable\n");
        exit(1);
    }
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
    exit;
}

$startTime = microtime(true);
$summary   = run_edit_job_worker($pdo, $maxJobs, $maxOutputs);
$elapsed   = round((microtime(true) - $startTime) * 1000);

if ($isCli) {
    printf(
        "[PTMD Worker] Done in %dms — %d outputs processed (%d failed) across %d job(s).\n",
        $elapsed,
        $summary['processed'],
        $summary['failed'],
        $summary['jobs']
    );
    if (!empty($summary['errors'])) {
        foreach ($summary['errors'] as $err) {
            fwrite(STDERR, "[PTMD Worker] Error: {$err}\n");
        }
    }
    exit($summary['failed'] > 0 ? 1 : 0);
}

echo json_encode([
    'ok'         => true,
    'elapsed_ms' => $elapsed,
    'message'    => sprintf(
        'Worker finished: %d outputs processed (%d failed) across %d job(s).',
        $summary['processed'],
        $summary['failed'],
        $summary['jobs']
    ),
    'summary'    => $summary,
]);

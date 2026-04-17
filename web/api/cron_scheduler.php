<?php
/**
 * PTMD — Cron Scheduler Worker (api/cron_scheduler.php)
 *
 * Dispatches due social_post_queue entries.
 * Protected by a secret token stored in site_settings (key: cron_token).
 *
 * Usage:
 *   GET /api/cron_scheduler.php?token=<cron_token>[&limit=20]
 *
 * Recommended cron (every 15 minutes):
 *   *\/15 * * * * curl -s "https://yourdomain.com/api/cron_scheduler.php?token=TOKEN"
 *
 * Retry policy:
 *   - After a failed dispatch the queue row stays as "queued" if below max retries.
 *   - After MAX_RETRIES failed attempts the row is marked "canceled".
 *   - Failed attempt count is derived from social_post_logs (status = "failed").
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/social_services.php';

const CRON_MAX_RETRIES = 3;

header('Content-Type: application/json; charset=utf-8');

// ── Token authentication ──────────────────────────────────────────────────────

$cronToken = site_setting('cron_token', '');
$provided  = trim((string) ($_GET['token'] ?? ''));

if ($cronToken === '' || !hash_equals($cronToken, $provided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// ── Database ──────────────────────────────────────────────────────────────────

$pdo = get_db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
    exit;
}

// ── Find due items ────────────────────────────────────────────────────────────

$batchLimit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));

// Sub-select counts failed log entries per queue row for retry logic
$stmt = $pdo->prepare(
    'SELECT q.*,
            (SELECT COUNT(*)
               FROM social_post_logs l
              WHERE l.queue_id = q.id AND l.status = "failed") AS fail_count
     FROM social_post_queue q
     WHERE q.status IN ("queued", "scheduled")
       AND q.scheduled_for <= NOW()
     ORDER BY q.scheduled_for ASC
     LIMIT :lim'
);
$stmt->bindValue(':lim', $batchLimit, PDO::PARAM_INT);
$stmt->execute();
$dueItems = $stmt->fetchAll();

$processed = 0;
$succeeded = 0;
$failed    = 0;
$skipped   = 0;
$errors    = [];

foreach ($dueItems as $item) {
    $failCount = (int) $item['fail_count'];

    // Max retries reached — cancel permanently
    if ($failCount >= CRON_MAX_RETRIES) {
        $pdo->prepare(
            'UPDATE social_post_queue
             SET status = "canceled", last_error = "Max retries exceeded", updated_at = NOW()
             WHERE id = :id'
        )->execute(['id' => $item['id']]);
        $skipped++;
        continue;
    }

    $result = dispatch_social_post($item);
    $processed++;

    if ($result['ok']) {
        $succeeded++;
    } else {
        $failed++;
        $errors[] = 'Queue #' . $item['id'] . ' (' . $item['platform'] . '): ' . ($result['error'] ?? 'unknown');

        // dispatch_social_post sets status to "failed"; reset to "queued" if retries remain
        if ($failCount + 1 < CRON_MAX_RETRIES) {
            $pdo->prepare(
                'UPDATE social_post_queue SET status = "queued", updated_at = NOW() WHERE id = :id'
            )->execute(['id' => $item['id']]);
        }
    }
}

echo json_encode([
    'ok'        => true,
    'processed' => $processed,
    'succeeded' => $succeeded,
    'failed'    => $failed,
    'skipped'   => $skipped,
    'errors'    => $errors,
    'timestamp' => date('c'),
]);

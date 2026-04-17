#!/usr/bin/env php
<?php
/**
 * PTMD Cron — Social Queue Dispatcher
 *
 * Picks due social_post_queue items (status = 'scheduled', scheduled_for <= NOW())
 * and dispatches them via dispatch_social_post().
 *
 * Run from project root cron:
 *   * * * * *  php /var/www/html/web/cron/dispatch_queue.php >> /var/log/ptmd_cron.log 2>&1
 *
 * Or for testing:
 *   php web/cron/dispatch_queue.php [--dry-run] [--limit=10]
 *
 * Note: Platform posting functions in inc/social_services.php are currently
 *       stubbed. Wire in real API credentials before enabling scheduled dispatch.
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
define('PTMD_CRON', true);

// Resolve web root relative to this file (web/cron/dispatch_queue.php → web/)
$webRoot = dirname(__DIR__);
chdir($webRoot);

// Bootstrap without session (CLI context)
$GLOBALS['config'] = require $webRoot . '/inc/config.php';
date_default_timezone_set($GLOBALS['config']['timezone']);

require_once $webRoot . '/inc/db.php';
require_once $webRoot . '/inc/functions.php';
require_once $webRoot . '/inc/social_services.php';

// ── CLI options ───────────────────────────────────────────────────────────────
$isDryRun = in_array('--dry-run', $argv ?? [], true);
$limit    = 20;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(100, (int) $m[1]));
    }
}

// ── Connect to DB ─────────────────────────────────────────────────────────────
$pdo = get_db();
if (!$pdo) {
    fwrite(STDERR, '[' . date('c') . '] ERROR: Database unavailable.' . PHP_EOL);
    exit(1);
}

// ── Fetch due items ───────────────────────────────────────────────────────────
$dueItems = $pdo->prepare(
    "SELECT * FROM social_post_queue
     WHERE status = 'scheduled'
       AND scheduled_for <= NOW()
     ORDER BY scheduled_for ASC
     LIMIT :lim"
);
$dueItems->bindValue(':lim', $limit, PDO::PARAM_INT);
$dueItems->execute();
$items = $dueItems->fetchAll();

if (empty($items)) {
    echo '[' . date('c') . '] No due items found.' . PHP_EOL;
    exit(0);
}

echo '[' . date('c') . '] Found ' . count($items) . ' due item(s).' . ($isDryRun ? ' [DRY RUN]' : '') . PHP_EOL;

// ── Dispatch ──────────────────────────────────────────────────────────────────
$succeeded = 0;
$failed    = 0;

foreach ($items as $item) {
    $logPrefix = '[' . date('c') . '] Queue #' . $item['id'] . ' (' . $item['platform'] . ')';

    if ($isDryRun) {
        echo $logPrefix . ' would be dispatched (dry run).' . PHP_EOL;
        continue;
    }

    // Mark as processing to avoid double-dispatch
    $pdo->prepare(
        "UPDATE social_post_queue SET status = 'processing', updated_at = NOW() WHERE id = :id AND status = 'scheduled'"
    )->execute(['id' => $item['id']]);

    $result = dispatch_social_post($item);

    if ($result['ok']) {
        echo $logPrefix . ' → posted (external ID: ' . ($result['external_post_id'] ?? 'n/a') . ')' . PHP_EOL;
        $succeeded++;
    } else {
        echo $logPrefix . ' → FAILED: ' . ($result['error'] ?? 'unknown') . PHP_EOL;
        $failed++;
    }
}

if (!$isDryRun) {
    echo '[' . date('c') . "] Done. Succeeded: {$succeeded}, Failed: {$failed}." . PHP_EOL;
}

exit($failed > 0 ? 1 : 0);

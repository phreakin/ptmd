<?php
/**
 * PTMD — Cron Queue Scheduler (api/cron_scheduler.php)
 *
 * Processes due social_post_queue items automatically.
 * Invoke via a system cron job or external scheduler, e.g.:
 *
 *   * * * * * curl -s -H "X-Cron-Secret: <secret>" https://yourdomain.com/api/cron_scheduler.php
 *
 * Security: requests must include the X-Cron-Secret header matching the
 * site_setting 'cron_secret' value.  If that setting is empty, the endpoint
 * is disabled (returns 503) to prevent accidental open access.
 *
 * Behaviour
 * ---------
 * 1. Load all queue items in status 'queued' or 'scheduled' where
 *    scheduled_for <= NOW() AND (retry_after IS NULL OR retry_after <= NOW()).
 * 2. Dispatch each item via dispatch_social_post().
 * 3. Items that fail with a retryable error class (transient / rate_limit /
 *    unknown) and have not reached PTMD_MAX_RETRIES are left in 'failed'
 *    status with retry_after set — the next cron run will pick them up.
 * 4. Items that fail with a terminal error class (auth / policy) or that have
 *    exhausted retries are moved to 'failed' permanently with a dead-letter
 *    note appended to last_error.
 * 5. Returns a JSON summary of what was processed.
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/social_services.php';

// ---------------------------------------------------------------------------
// Secret-based authentication
// ---------------------------------------------------------------------------

$cronSecret = site_setting('cron_secret', '');

if ($cronSecret === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Cron scheduler is not enabled. Set the cron_secret site setting.']);
    exit;
}

$providedSecret = (string) ($_SERVER['HTTP_X_CRON_SECRET'] ?? '');
if (!hash_equals($cronSecret, $providedSecret)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized.']);
    exit;
}

// ---------------------------------------------------------------------------
// Load due queue items
// ---------------------------------------------------------------------------

$pdo = get_db();

if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable.']);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT * FROM social_post_queue
     WHERE status IN ('queued', 'scheduled', 'failed')
       AND scheduled_for <= NOW()
       AND (retry_after IS NULL OR retry_after <= NOW())
       AND (retry_count IS NULL OR retry_count < :max_retries)
     ORDER BY scheduled_for ASC
     LIMIT 50"
);
$stmt->execute(['max_retries' => PTMD_MAX_RETRIES]);
$items = $stmt->fetchAll();

// ---------------------------------------------------------------------------
// Process each item
// ---------------------------------------------------------------------------

$processed  = 0;
$dispatched = 0;
$skipped    = 0;
$failed     = 0;
$deadLetter = 0;
$errors     = [];

foreach ($items as $item) {
    $processed++;
    $retryCount = (int) ($item['retry_count'] ?? 0);

    $result = dispatch_social_post($item);

    if ($result['ok']) {
        $dispatched++;
        continue;
    }

    // Classify and act on failure
    $errorClass = classify_dispatch_error((string) ($result['error'] ?? ''));
    $isTerminal = in_array($errorClass, [PTMD_ERR_AUTH, PTMD_ERR_POLICY], true);
    $isExhausted = ($retryCount + 1) >= PTMD_MAX_RETRIES;

    if ($isTerminal || $isExhausted) {
        // Dead-letter: mark with remediation note
        $deadLetter++;
        $remediationNote = _ptmd_dead_letter_note($errorClass, $item['platform']);
        $pdo->prepare(
            "UPDATE social_post_queue
             SET status = 'failed',
                 last_error = CONCAT(COALESCE(last_error,''), '\n[DEAD LETTER] ', :note),
                 updated_at = NOW()
             WHERE id = :id"
        )->execute([
            'note' => $remediationNote,
            'id'   => (int) $item['id'],
        ]);
    } else {
        $failed++;
    }

    $errors[] = [
        'id'          => (int) $item['id'],
        'platform'    => $item['platform'],
        'error_class' => $errorClass,
        'terminal'    => $isTerminal || $isExhausted,
        'error'       => $result['error'],
    ];
}

// ---------------------------------------------------------------------------
// Response
// ---------------------------------------------------------------------------

header('Content-Type: application/json');
echo json_encode([
    'ok'         => true,
    'processed'  => $processed,
    'dispatched' => $dispatched,
    'failed'     => $failed,
    'dead_letter'=> $deadLetter,
    'skipped'    => $skipped,
    'errors'     => $errors,
    'ran_at'     => date('c'),
], JSON_UNESCAPED_UNICODE);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Return an actionable remediation message for dead-letter items.
 *
 * @param string $errorClass  One of the PTMD_ERR_* constants.
 * @param string $platform    Platform name.
 * @return string
 */
function _ptmd_dead_letter_note(string $errorClass, string $platform): string
{
    return match ($errorClass) {
        PTMD_ERR_AUTH        => "Auth failure on {$platform}: token expired or revoked. Reconnect the account in Social Accounts.",
        PTMD_ERR_POLICY      => "Policy rejection on {$platform}: content may violate platform rules. Review caption/media before requeuing.",
        PTMD_ERR_RATE_LIMIT  => "Rate limit retries exhausted on {$platform}. Requeue manually after cooling off.",
        PTMD_ERR_TRANSIENT   => "Transient error retries exhausted on {$platform}. Check platform status and requeue.",
        default              => "Max retries exhausted on {$platform}. Check logs for details and requeue manually.",
    };
}

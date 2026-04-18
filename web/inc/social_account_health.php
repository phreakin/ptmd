<?php
/**
 * PTMD — Social Account Health  (inc/social_account_health.php)
 *
 * Pure utility library for monitoring platform account credential health.
 * All public functions either:
 *  - Accept only primitive / array arguments (pure, no DB I/O), or
 *  - Accept a PDO instance for database-backed queries.
 *
 * This separation makes every pure function trivially unit-testable
 * without mocking the database.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Token / credential health check  (pure — no DB)
// ---------------------------------------------------------------------------

/**
 * Examine a social_accounts row and return a health-check result array:
 *
 *  [
 *    'healthy'          => bool,
 *    'status'           => 'ok'|'expiring_soon'|'expired'|'revoked'|'error'|'unconfigured',
 *    'issues'           => string[],   // human-readable problems found
 *    'days_until_expiry'=> int|null,   // null when no expiry date stored
 *  ]
 *
 * @param array  $account    Row from social_accounts (with token_expires_at, status, auth_config_json)
 * @param int    $warnDays   Warn when token expires within this many days (default 7)
 * @param string $nowIso     ISO-8601 "now" string for deterministic testing (default: current UTC time)
 */
function ptmd_check_account_health(array $account, int $warnDays = 7, string $nowIso = ''): array
{
    $issues = [];
    $status = 'ok';
    $daysUntilExpiry = null;

    // 1. Not active
    if (!(int) ($account['is_active'] ?? 0)) {
        $issues[] = 'Account is marked inactive.';
        $status   = 'error';
    }

    // 2. Revoked / error status from DB
    $dbStatus = $account['status'] ?? 'active';
    if ($dbStatus === 'revoked') {
        $issues[] = 'Account credentials have been revoked.';
        $status   = 'revoked';
    } elseif ($dbStatus === 'error') {
        $issues[] = 'Account is in error state: ' . ($account['last_error'] ?? 'unknown error');
        $status   = 'error';
    }

    // 3. No credentials at all
    $authJson = $account['auth_config_json'] ?? null;
    if ($authJson === null || $authJson === '' || $authJson === '{}' || $authJson === 'null') {
        $issues[] = 'No credentials configured. Complete platform onboarding.';
        $status   = 'unconfigured';
    }

    // 4. Token expiry check
    $expiresAt = $account['token_expires_at'] ?? null;
    if ($expiresAt !== null && $expiresAt !== '') {
        $now     = $nowIso !== '' ? new \DateTimeImmutable($nowIso, new \DateTimeZone('UTC')) :
                                    new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expDt   = new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC'));
        $diffSec = $expDt->getTimestamp() - $now->getTimestamp();
        $daysUntilExpiry = (int) floor($diffSec / 86400);

        if ($diffSec <= 0) {
            $issues[] = 'Access token has expired (expired: ' . $expiresAt . ').';
            $status   = 'expired';
        } elseif ($daysUntilExpiry <= $warnDays) {
            $issues[] = 'Access token expires in ' . $daysUntilExpiry . ' day(s). Refresh soon.';
            if ($status === 'ok') {
                $status = 'expiring_soon';
            }
        }
    }

    return [
        'healthy'           => empty($issues),
        'status'            => $status,
        'issues'            => $issues,
        'days_until_expiry' => $daysUntilExpiry,
    ];
}

// ---------------------------------------------------------------------------
// Database-backed queries
// ---------------------------------------------------------------------------

/**
 * Return all accounts whose token expires within $daysAhead days (or is already expired).
 */
function ptmd_get_expiring_accounts(object $pdo, int $daysAhead = 7): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM social_accounts
         WHERE is_active = 1
           AND token_expires_at IS NOT NULL
           AND token_expires_at <= DATE_ADD(NOW(), INTERVAL :days DAY)
         ORDER BY token_expires_at ASC'
    );
    $stmt->execute(['days' => $daysAhead]);
    return $stmt->fetchAll();
}

/**
 * Return platform names that have no active + fully-configured account.
 * A platform is "failing" when every account row for it is either inactive,
 * unconfigured (null auth_config_json), or in an error/revoked/expired status.
 *
 * Returns an array of platform name strings.
 */
function ptmd_get_failing_platforms(object $pdo): array
{
    $rows = $pdo->query(
        'SELECT platform, MAX(is_active) AS has_active,
                COUNT(CASE WHEN auth_config_json IS NOT NULL AND auth_config_json != "null" THEN 1 END) AS configured,
                SUM(CASE WHEN status IN ("active","expiring_soon") THEN 1 ELSE 0 END) AS healthy_count
         FROM social_accounts
         GROUP BY platform'
    )->fetchAll();

    $failing = [];
    foreach ($rows as $row) {
        if (!(int) $row['has_active'] || (int) $row['configured'] === 0 || (int) $row['healthy_count'] === 0) {
            $failing[] = $row['platform'];
        }
    }
    return $failing;
}

/**
 * Return queue backlog counts grouped by status and platform.
 * Result: [ ['platform' => string, 'status' => string, 'count' => int], ... ]
 */
function ptmd_get_queue_backlog(object $pdo): array
{
    return $pdo->query(
        'SELECT platform, status, COUNT(*) AS count
         FROM social_post_queue
         WHERE status IN ("draft","queued","scheduled","failed")
         GROUP BY platform, status
         ORDER BY platform, status'
    )->fetchAll();
}

/**
 * Return a compact alert summary for the admin dashboard banner:
 * [
 *   'expiring_soon'     => int,   // accounts expiring within 7 days
 *   'expired'           => int,   // accounts already expired
 *   'unconfigured'      => int,   // accounts with no credentials
 *   'failing_platforms' => int,   // count of failing platform names
 *   'queue_failed'      => int,   // total failed queue items
 *   'queue_draft'       => int,   // total draft queue items needing review
 *   'has_alerts'        => bool,
 * ]
 */
function ptmd_get_alert_summary(object $pdo): array
{
    // Expiry counts
    $expiryRow = $pdo->query(
        'SELECT
           SUM(CASE WHEN token_expires_at IS NOT NULL AND token_expires_at <= NOW() THEN 1 ELSE 0 END) AS expired,
           SUM(CASE WHEN token_expires_at IS NOT NULL
                    AND token_expires_at > NOW()
                    AND token_expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS expiring_soon,
           SUM(CASE WHEN auth_config_json IS NULL OR auth_config_json = "null" OR auth_config_json = "" THEN 1 ELSE 0 END) AS unconfigured
         FROM social_accounts
         WHERE is_active = 1'
    )->fetch();

    // Queue counts
    $queueRow = $pdo->query(
        'SELECT
           SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) AS queue_failed,
           SUM(CASE WHEN status = "draft"  THEN 1 ELSE 0 END) AS queue_draft
         FROM social_post_queue'
    )->fetch();

    $failingPlatforms = ptmd_get_failing_platforms($pdo);

    $summary = [
        'expiring_soon'     => (int) ($expiryRow['expiring_soon']  ?? 0),
        'expired'           => (int) ($expiryRow['expired']        ?? 0),
        'unconfigured'      => (int) ($expiryRow['unconfigured']   ?? 0),
        'failing_platforms' => count($failingPlatforms),
        'queue_failed'      => (int) ($queueRow['queue_failed']    ?? 0),
        'queue_draft'       => (int) ($queueRow['queue_draft']     ?? 0),
    ];

    $summary['has_alerts'] = (
        $summary['expiring_soon']    > 0 ||
        $summary['expired']          > 0 ||
        $summary['unconfigured']     > 0 ||
        $summary['failing_platforms']> 0 ||
        $summary['queue_failed']     > 0
    );

    return $summary;
}

/**
 * Write the result of a health check back to the social_accounts row.
 * Updates: last_health_check_at, last_error, status.
 */
function ptmd_persist_health_check(object $pdo, int $accountId, array $healthResult): void
{
    $newStatus = match ($healthResult['status']) {
        'ok', 'expiring_soon' => 'active',
        'expired'             => 'expired',
        'revoked'             => 'revoked',
        default               => 'error',
    };

    $lastError = empty($healthResult['issues']) ? null : implode(' | ', $healthResult['issues']);

    $pdo->prepare(
        'UPDATE social_accounts
         SET last_health_check_at = NOW(),
             last_error           = :err,
             status               = :status,
             updated_at           = NOW()
         WHERE id = :id'
    )->execute([
        'err'    => $lastError,
        'status' => $newStatus,
        'id'     => $accountId,
    ]);
}

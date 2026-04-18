<?php
/**
 * PTMD — social_account_health_test.php
 *
 * Tests for inc/social_account_health.php
 * Pure-function tests use no DB.
 * DB-backed tests use duck-typed mock PDOs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/social_account_health.php';

// ===========================================================================
// 1. Healthy account — configured, active, not expiring
// ===========================================================================

$healthyAccount = [
    'is_active'        => 1,
    'status'           => 'active',
    'auth_config_json' => '{"access_token":"abc123"}',
    'token_expires_at' => null,
    'last_error'       => null,
];

$result = ptmd_check_account_health($healthyAccount, 7, '2026-04-18T10:00:00');
ptmd_assert_true($result['healthy'],          'health: healthy account returns healthy=true');
ptmd_assert_same($result['status'], 'ok',     'health: healthy account status is ok');
ptmd_assert_same($result['issues'], [],       'health: healthy account has no issues');

// ===========================================================================
// 2. Inactive account
// ===========================================================================

$inactiveAccount = array_merge($healthyAccount, ['is_active' => 0]);
$result = ptmd_check_account_health($inactiveAccount, 7, '2026-04-18T10:00:00');
ptmd_assert_true(!$result['healthy'],         'health: inactive account is not healthy');
ptmd_assert_true(
    (bool) array_filter($result['issues'], fn($i) => str_contains($i, 'inactive')),
    'health: inactive account has inactive issue message'
);

// ===========================================================================
// 3. Revoked account
// ===========================================================================

$revokedAccount = array_merge($healthyAccount, ['status' => 'revoked']);
$result = ptmd_check_account_health($revokedAccount, 7, '2026-04-18T10:00:00');
ptmd_assert_true(!$result['healthy'],              'health: revoked account is not healthy');
ptmd_assert_same($result['status'], 'revoked',     'health: revoked account status=revoked');
ptmd_assert_true(
    (bool) array_filter($result['issues'], fn($i) => str_contains($i, 'revoked')),
    'health: revoked account has revoked issue message'
);

// ===========================================================================
// 4. Error-state account with message
// ===========================================================================

$errorAccount = array_merge($healthyAccount, ['status' => 'error', 'last_error' => 'API 401']);
$result = ptmd_check_account_health($errorAccount, 7, '2026-04-18T10:00:00');
ptmd_assert_true(!$result['healthy'],           'health: error account is not healthy');
ptmd_assert_same($result['status'], 'error',    'health: error account status=error');
ptmd_assert_true(
    (bool) array_filter($result['issues'], fn($i) => str_contains($i, 'API 401')),
    'health: error account surfaces last_error in issues'
);

// ===========================================================================
// 5. Unconfigured account (null auth)
// ===========================================================================

$unconfigured = array_merge($healthyAccount, ['auth_config_json' => null]);
$result = ptmd_check_account_health($unconfigured, 7, '2026-04-18T10:00:00');
ptmd_assert_true(!$result['healthy'],                'health: unconfigured account is not healthy');
ptmd_assert_same($result['status'], 'unconfigured',  'health: unconfigured status=unconfigured');

// ===========================================================================
// 6. Token expiry — expired
// ===========================================================================

$expiredAccount = array_merge($healthyAccount, ['token_expires_at' => '2026-03-01 00:00:00']);
$result = ptmd_check_account_health($expiredAccount, 7, '2026-04-18T10:00:00');
ptmd_assert_true(!$result['healthy'],           'health: expired token is not healthy');
ptmd_assert_same($result['status'], 'expired',  'health: expired token status=expired');
ptmd_assert_true(
    $result['days_until_expiry'] < 0,
    'health: expired days_until_expiry is negative'
);

// ===========================================================================
// 7. Token expiry — expiring soon (within warn window)
// ===========================================================================

$expiringSoon = array_merge($healthyAccount, ['token_expires_at' => '2026-04-22 00:00:00']);
$result = ptmd_check_account_health($expiringSoon, 7, '2026-04-18T10:00:00');
ptmd_assert_true(!$result['healthy'],                   'health: expiring-soon account is not healthy');
ptmd_assert_same($result['status'], 'expiring_soon',    'health: expiring-soon status=expiring_soon');
ptmd_assert_true(
    $result['days_until_expiry'] >= 0 && $result['days_until_expiry'] <= 7,
    'health: expiring-soon days_until_expiry in [0..7]'
);

// ===========================================================================
// 8. Token expiry — healthy (more than warnDays away)
// ===========================================================================

$farFuture = array_merge($healthyAccount, ['token_expires_at' => '2027-01-01 00:00:00']);
$result = ptmd_check_account_health($farFuture, 7, '2026-04-18T10:00:00');
ptmd_assert_true($result['healthy'],        'health: far-future expiry is healthy');
ptmd_assert_same($result['status'], 'ok',   'health: far-future expiry status=ok');
ptmd_assert_true(
    $result['days_until_expiry'] > 7,
    'health: far-future expiry days_until_expiry > 7'
);

// ===========================================================================
// 9. ptmd_check_account_health — empty auth_config_json variations
// ===========================================================================

foreach (['', '{}', 'null'] as $emptyAuth) {
    $emptyAccount = array_merge($healthyAccount, ['auth_config_json' => $emptyAuth]);
    $result = ptmd_check_account_health($emptyAccount, 7, '2026-04-18T10:00:00');
    ptmd_assert_same(
        $result['status'],
        'unconfigured',
        'health: auth_config_json="' . $emptyAuth . '" is unconfigured'
    );
}

// ===========================================================================
// 10. ptmd_get_expiring_accounts — DB mock
// ===========================================================================

class ExpiringAccountsStmt
{
    public function execute(array $p): void {}
    public function fetchAll(): array
    {
        return [
            ['id' => 1, 'platform' => 'TikTok', 'token_expires_at' => '2026-04-20 00:00:00'],
        ];
    }
}

class ExpiringAccountsMockPdo
{
    public function prepare(string $sql): ExpiringAccountsStmt { return new ExpiringAccountsStmt(); }
}

$expPdo    = new ExpiringAccountsMockPdo();
$expiring  = ptmd_get_expiring_accounts($expPdo, 7);
ptmd_assert_true(count($expiring) === 1, 'health DB: get_expiring_accounts returns mocked row');
ptmd_assert_same($expiring[0]['platform'], 'TikTok', 'health DB: expiring platform is TikTok');

// ===========================================================================
// 11. ptmd_get_failing_platforms — DB mock (one configured+healthy, one not)
// ===========================================================================

class FailingPlatformsStmt
{
    public function fetchAll(): array
    {
        return [
            ['platform' => 'YouTube',  'has_active' => 1, 'configured' => 1, 'healthy_count' => 1],
            ['platform' => 'TikTok',   'has_active' => 0, 'configured' => 1, 'healthy_count' => 0],
            ['platform' => 'X',        'has_active' => 1, 'configured' => 0, 'healthy_count' => 0],
        ];
    }
}

class FailingPlatformsMockPdo
{
    public function query(string $sql): FailingPlatformsStmt { return new FailingPlatformsStmt(); }
}

$failPdo     = new FailingPlatformsMockPdo();
$failing     = ptmd_get_failing_platforms($failPdo);
ptmd_assert_true(!in_array('YouTube', $failing, true), 'health DB: YouTube is not failing (healthy)');
ptmd_assert_true(in_array('TikTok',  $failing, true),  'health DB: TikTok is failing (inactive)');
ptmd_assert_true(in_array('X',       $failing, true),  'health DB: X is failing (unconfigured)');

// ===========================================================================
// 12. ptmd_get_queue_backlog — DB mock
// ===========================================================================

class QueueBacklogStmt
{
    public function fetchAll(): array
    {
        return [
            ['platform' => 'TikTok', 'status' => 'failed',  'count' => 3],
            ['platform' => 'TikTok', 'status' => 'queued',  'count' => 7],
            ['platform' => 'YouTube','status' => 'draft',   'count' => 2],
        ];
    }
}

class QueueBacklogMockPdo
{
    public function query(string $sql): QueueBacklogStmt { return new QueueBacklogStmt(); }
}

$backlogPdo  = new QueueBacklogMockPdo();
$backlog     = ptmd_get_queue_backlog($backlogPdo);
ptmd_assert_true(count($backlog) === 3, 'health DB: queue backlog has 3 rows');

// ===========================================================================
// 13. ptmd_get_alert_summary — DB mock
// ===========================================================================

class AlertSummaryExpiryStmt
{
    public function fetch(): array
    {
        return ['expired' => 1, 'expiring_soon' => 2, 'unconfigured' => 1];
    }
    public function fetchAll(): array
    {
        // For get_failing_platforms sub-call
        return [
            ['platform' => 'Pinterest Idea Pins', 'has_active' => 1, 'configured' => 0, 'healthy_count' => 0],
        ];
    }
}

class AlertSummaryQueueStmt
{
    public function fetch(): array
    {
        return ['queue_failed' => 5, 'queue_draft' => 3];
    }
}

class AlertSummaryMockPdo
{
    private int $callCount = 0;
    public function query(string $sql): AlertSummaryExpiryStmt|AlertSummaryQueueStmt
    {
        $this->callCount++;
        if ($this->callCount === 1) {
            return new AlertSummaryExpiryStmt(); // expiry counts
        }
        if ($this->callCount === 2) {
            return new AlertSummaryQueueStmt();  // queue counts
        }
        return new AlertSummaryExpiryStmt();     // failing_platforms sub-query
    }
}

$alertPdo = new AlertSummaryMockPdo();
$summary  = ptmd_get_alert_summary($alertPdo);

ptmd_assert_same($summary['expired'],       1,    'health DB: alert_summary expired=1');
ptmd_assert_same($summary['expiring_soon'], 2,    'health DB: alert_summary expiring_soon=2');
ptmd_assert_same($summary['unconfigured'],  1,    'health DB: alert_summary unconfigured=1');
ptmd_assert_same($summary['queue_failed'],  5,    'health DB: alert_summary queue_failed=5');
ptmd_assert_same($summary['queue_draft'],   3,    'health DB: alert_summary queue_draft=3');
ptmd_assert_true($summary['has_alerts'],          'health DB: alert_summary has_alerts=true');

// ===========================================================================
// 14. ptmd_get_alert_summary — no alerts path
// ===========================================================================

class NoAlertExpiryStmt
{
    public function fetch(): array
    {
        return ['expired' => 0, 'expiring_soon' => 0, 'unconfigured' => 0];
    }
    public function fetchAll(): array { return []; }
}

class NoAlertQueueStmt
{
    public function fetch(): array { return ['queue_failed' => 0, 'queue_draft' => 0]; }
    public function fetchAll(): array { return []; }
}

class NoAlertMockPdo
{
    private int $callCount = 0;
    public function query(string $sql): NoAlertExpiryStmt|NoAlertQueueStmt
    {
        $this->callCount++;
        return $this->callCount <= 1 ? new NoAlertExpiryStmt() : new NoAlertQueueStmt();
    }
}

$noAlertPdo = new NoAlertMockPdo();
$noSummary  = ptmd_get_alert_summary($noAlertPdo);
ptmd_assert_true(!$noSummary['has_alerts'], 'health DB: no-alerts summary has_alerts=false');

// ===========================================================================
// 15. ptmd_persist_health_check — correct status mapping
// ===========================================================================

class PersistHealthStmt
{
    public array $lastParams = [];
    public function execute(array $p): void { $this->lastParams = $p; }
}

class PersistHealthMockPdo
{
    public PersistHealthStmt $stmt;
    public function __construct() { $this->stmt = new PersistHealthStmt(); }
    public function prepare(string $sql): PersistHealthStmt { return $this->stmt; }
}

$persistPdo = new PersistHealthMockPdo();

ptmd_persist_health_check($persistPdo, 42, ['status' => 'ok', 'issues' => []]);
ptmd_assert_same($persistPdo->stmt->lastParams['status'], 'active', 'health persist: ok → status=active');
ptmd_assert_same($persistPdo->stmt->lastParams['id'],     42,       'health persist: correct id written');
ptmd_assert_same($persistPdo->stmt->lastParams['err'],    null,     'health persist: no error when healthy');

ptmd_persist_health_check($persistPdo, 5, ['status' => 'expired', 'issues' => ['Token expired']]);
ptmd_assert_same($persistPdo->stmt->lastParams['status'], 'expired', 'health persist: expired → status=expired');
ptmd_assert_true(
    str_contains($persistPdo->stmt->lastParams['err'] ?? '', 'Token expired'),
    'health persist: error text written for expired'
);

ptmd_persist_health_check($persistPdo, 7, ['status' => 'revoked', 'issues' => ['Credentials revoked', 'Extra issue']]);
ptmd_assert_same($persistPdo->stmt->lastParams['status'], 'revoked', 'health persist: revoked → status=revoked');
ptmd_assert_true(
    str_contains($persistPdo->stmt->lastParams['err'] ?? '', '|'),
    'health persist: multiple issues joined with |'
);

ptmd_persist_health_check($persistPdo, 3, ['status' => 'unconfigured', 'issues' => ['No creds']]);
ptmd_assert_same($persistPdo->stmt->lastParams['status'], 'error', 'health persist: unconfigured → status=error');

ptmd_persist_health_check($persistPdo, 4, ['status' => 'expiring_soon', 'issues' => ['Expires in 3 days']]);
ptmd_assert_same($persistPdo->stmt->lastParams['status'], 'active', 'health persist: expiring_soon → status=active');

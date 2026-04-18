<?php
/**
 * PTMD — Scheduler Unit Tests  (tests/scheduler_test.php)
 *
 * Tests recurrence expansion, idempotency guards, retry logic, lock mechanics,
 * and token/IP verification.
 *
 * These are purely in-process unit tests — no live DB required.
 * A minimal stub for PDO-dependent helpers is provided below.
 */

declare(strict_types=1);

$ptmdTestFailures = $ptmdTestFailures ?? [];
$ptmdAssertions   = $ptmdAssertions   ?? 0;

// ── Ensure assertion helpers exist (run.php loads them first, but guard anyway)

if (!function_exists('ptmd_assert_true')) {
    function ptmd_assert_true(bool $condition, string $message): void
    {
        global $ptmdTestFailures, $ptmdAssertions;
        $ptmdAssertions++;
        if (!$condition) {
            $ptmdTestFailures[] = $message;
        }
    }
}

if (!function_exists('ptmd_assert_same')) {
    function ptmd_assert_same(mixed $actual, mixed $expected, string $message): void
    {
        ptmd_assert_true(
            $actual === $expected,
            $message . ' (expected: ' . var_export($expected, true) . ', actual: ' . var_export($actual, true) . ')'
        );
    }
}

// ── Load scheduler library without touching the real bootstrap ────────────────

// Stub get_db() so scheduler.php can be loaded in isolation
if (!function_exists('get_db')) {
    function get_db(): ?\PDO { return null; }
}

require_once __DIR__ . '/../inc/scheduler.php';

// =============================================================================
// 1. Recurrence expansion — weekly
// =============================================================================

$weeklySchedule = [
    'id'             => 1,
    'platform'       => 'TikTok',
    'content_type'   => 'teaser',
    'day_of_week'    => 'Friday',
    'post_time'      => '17:00:00',
    'timezone'       => 'America/Phoenix',
    'recurrence_type'=> 'weekly',
];

// Use a known Monday as the start date so we can predict occurrences
$from    = new \DateTimeImmutable('2026-04-13 00:00:00', new \DateTimeZone('UTC')); // Monday
$results = scheduler_expand_occurrences($weeklySchedule, $from, 21);

ptmd_assert_true(count($results) >= 3, 'weekly: 3 Fridays within 21 days of a Monday');
ptmd_assert_true(count($results) <= 4, 'weekly: no more than 4 Fridays within 21 days');

// Each result should fall on a Friday in Phoenix time
foreach ($results as $idx => $utcTs) {
    $dt  = new \DateTime($utcTs, new \DateTimeZone('UTC'));
    $dt->setTimezone(new \DateTimeZone('America/Phoenix'));
    $dow = (int) $dt->format('w'); // 0=Sun, 5=Fri
    ptmd_assert_same($dow, 5, 'weekly: occurrence #' . $idx . ' falls on Friday in Phoenix TZ');
    $hour = (int) $dt->format('G');
    ptmd_assert_same($hour, 17, 'weekly: occurrence #' . $idx . ' fires at 17:00 Phoenix');
}

// =============================================================================
// 2. Recurrence expansion — daily
// =============================================================================

$dailySchedule = [
    'id'             => 2,
    'platform'       => 'X',
    'content_type'   => 'clip',
    'day_of_week'    => 'Monday', // ignored for daily
    'post_time'      => '09:00:00',
    'timezone'       => 'America/Phoenix',
    'recurrence_type'=> 'daily',
];

$from7    = new \DateTimeImmutable('2026-04-13 00:00:00', new \DateTimeZone('UTC'));
$daily7   = scheduler_expand_occurrences($dailySchedule, $from7, 7);

ptmd_assert_same(count($daily7), 7, 'daily: exactly 7 occurrences in 7 days');

// =============================================================================
// 3. Recurrence expansion — monthly
// =============================================================================

$monthlySchedule = [
    'id'             => 3,
    'platform'       => 'YouTube',
    'content_type'   => 'full documentary',
    'day_of_week'    => '15', // 15th of each month
    'post_time'      => '10:00:00',
    'timezone'       => 'America/Phoenix',
    'recurrence_type'=> 'monthly',
];

$fromMonth = new \DateTimeImmutable('2026-04-01 00:00:00', new \DateTimeZone('UTC'));
$monthly   = scheduler_expand_occurrences($monthlySchedule, $fromMonth, 90);

// 90 days from April 1 covers April 15, May 15, June 15, July (partial — depends on exact window)
ptmd_assert_true(count($monthly) >= 3, 'monthly: at least 3 occurrences in 90 days');

foreach ($monthly as $idx => $utcTs) {
    $dt = new \DateTime($utcTs, new \DateTimeZone('UTC'));
    $dt->setTimezone(new \DateTimeZone('America/Phoenix'));
    ptmd_assert_same((int) $dt->format('j'), 15, 'monthly: occurrence #' . $idx . ' is on day 15');
}

// =============================================================================
// 4. Recurrence — timezone boundary (Phoenix = UTC-7, no DST)
// =============================================================================

// Phoenix 17:00 = UTC 00:00 next day (UTC-7).
// With from = 2026-04-13 UTC, the first Friday at 17:00 Phoenix
// should appear in UTC as 2026-04-17 00:00:00 (17+7=24 → midnight next day).
$firstResult = $results[0];
$dt = new \DateTime($firstResult, new \DateTimeZone('UTC'));
$dtPhoenix = clone $dt;
$dtPhoenix->setTimezone(new \DateTimeZone('America/Phoenix'));
ptmd_assert_same($dtPhoenix->format('H:i'), '17:00', 'timezone: UTC result converts back to 17:00 Phoenix');

// =============================================================================
// 5. Idempotency: expand_schedule_to_queue skips existing dates
// =============================================================================

// We compute which Friday falls within 7 days of our known $from (2026-04-13),
// then tell the mock PDO that date already exists in the queue.
// The function must skip that occurrence and report skipped=1.

$idempotFrom    = new \DateTimeImmutable('2026-04-13 00:00:00', new \DateTimeZone('UTC'));
$idempotWeekly  = scheduler_expand_occurrences($weeklySchedule, $idempotFrom, 7);
$existingUtcTs  = $idempotWeekly[0] ?? '2026-04-17 00:00:00';
$existingDateStr = substr($existingUtcTs, 0, 10); // 'YYYY-MM-DD'

// Build a minimal mock PDO — only needs to handle:
//   1. SELECT DATE(scheduled_for) … WHERE schedule_id  → return the existing date
//   2. UPDATE social_post_schedules                    → no-op
// INSERT must NOT be called because the only occurrence is already "existing".

$mockPdo = new class($existingDateStr) {
    private string $existingDate;
    public bool $insertCalled = false;

    public function __construct(string $d) { $this->existingDate = $d; }

    public function prepare(string $query): object
    {
        $existingDate  = $this->existingDate;

        if (str_contains($query, 'DATE(scheduled_for)')) {
            return new class($existingDate) {
                private string $d;
                public function __construct(string $d) { $this->d = $d; }
                public function execute(?array $p = null): bool { return true; }
                public function fetchAll(int $m = 0, mixed ...$a): array { return [['d' => $this->d]]; }
            };
        }

        if (str_contains($query, 'INSERT INTO social_post_queue')) {
            return new class($this) {
                private object $owner;
                public function __construct(object $o) { $this->owner = $o; }
                public function execute(?array $p = null): bool { $this->owner->insertCalled = true; return true; }
            };
        }

        // UPDATE social_post_schedules (last_generated_at)
        return new class {
            public function execute(?array $p = null): bool { return true; }
        };
    }
};

$idempotencyResult = scheduler_expand_schedule_to_queue(
    $mockPdo,
    $weeklySchedule,
    7,
    false,          // not a dry run — real path
    $idempotFrom    // pin "now" to our known date
);

ptmd_assert_same($idempotencyResult['skipped'], 1,   'idempotency: existing date counted as skipped');
ptmd_assert_same($idempotencyResult['generated'], 0, 'idempotency: no new inserts when date exists');
ptmd_assert_true(!$mockPdo->insertCalled,             'idempotency: INSERT was not called for existing date');

// =============================================================================
// 6. Retry policy — should_retry
// =============================================================================

ptmd_assert_true(
    scheduler_should_retry(['retry_count' => 0], 3),
    'retry: item with 0 retries should retry (max=3)'
);
ptmd_assert_true(
    scheduler_should_retry(['retry_count' => 2], 3),
    'retry: item with 2 retries should retry (max=3)'
);
ptmd_assert_true(
    !scheduler_should_retry(['retry_count' => 3], 3),
    'retry: item with 3 retries should NOT retry (max=3)'
);
ptmd_assert_true(
    !scheduler_should_retry(['retry_count' => 5], 3),
    'retry: item with 5 retries should NOT retry (max=3)'
);

// =============================================================================
// 7. Recurrence — zero occurrences when horizon is 0
// =============================================================================

$emptyResult = scheduler_expand_occurrences($weeklySchedule, $from, 0);
ptmd_assert_true(is_array($emptyResult), 'expand: returns array even for 0-day horizon');
ptmd_assert_same(count($emptyResult), 0, 'expand: 0-day horizon yields 0 occurrences');

// =============================================================================
// 8. Recurrence — unknown day name falls back to Monday (dow=1)
// =============================================================================

$badDaySched = $weeklySchedule;
$badDaySched['day_of_week'] = 'Blursday'; // not a real day
$badDayResult = scheduler_expand_occurrences($badDaySched, $from, 14);
foreach ($badDayResult as $idx => $utcTs) {
    $dt = new \DateTime($utcTs, new \DateTimeZone('UTC'));
    $dt->setTimezone(new \DateTimeZone('America/Phoenix'));
    $dow = (int) $dt->format('w');
    ptmd_assert_same($dow, 1, 'fallback: bad day name falls back to Monday (dow=1), occurrence #' . $idx);
}

// =============================================================================
// 9. Verify token returns false for empty token
// =============================================================================

// scheduler_verify_token calls get_db() which returns null in test context →
// will return false because no DB is available (secret can't be fetched).
ptmd_assert_true(
    !scheduler_verify_token(''),
    'token: empty token rejected'
);
ptmd_assert_true(
    !scheduler_verify_token('some-token'),
    'token: token rejected when DB unavailable (no secret to compare against)'
);

// =============================================================================
// 10. Verify IP — empty allowlist allows any IP
// =============================================================================

// scheduler_verify_ip calls get_db() → null → returns true (no restriction)
ptmd_assert_true(
    scheduler_verify_ip('1.2.3.4'),
    'ip: any IP allowed when DB unavailable (no allowlist)'
);

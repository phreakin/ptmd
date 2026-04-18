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

// =============================================================================
// 11. scheduler_validate_queue_item — platform required
// =============================================================================

$missingPlatform = ['platform' => '', 'scheduled_for' => '2026-06-01 09:00:00', 'caption' => 'hello'];
$errs = scheduler_validate_queue_item($missingPlatform);
ptmd_assert_true(count($errs) > 0, 'validate: empty platform raises error');
ptmd_assert_true(
    in_array('Platform is required.', $errs, true),
    'validate: platform-required message present'
);

// =============================================================================
// 12. scheduler_validate_queue_item — scheduled_for required
// =============================================================================

$missingSched = ['platform' => 'TikTok', 'scheduled_for' => '', 'caption' => ''];
$errs = scheduler_validate_queue_item($missingSched);
ptmd_assert_true(count($errs) > 0, 'validate: empty scheduled_for raises error');
ptmd_assert_true(
    in_array('scheduled_for is required.', $errs, true),
    'validate: scheduled_for-required message present'
);

// =============================================================================
// 13. scheduler_validate_queue_item — video platform without asset warns
// =============================================================================

$noAsset = ['platform' => 'TikTok', 'scheduled_for' => '2026-06-01 09:00:00', 'caption' => '', 'asset_path' => '', 'clip_id' => 0];
$errs = scheduler_validate_queue_item($noAsset);
ptmd_assert_true(count($errs) > 0, 'validate: TikTok without asset raises warning');
ptmd_assert_true(
    (bool) array_filter($errs, fn($e) => str_contains($e, 'TikTok')),
    'validate: TikTok warning message mentions platform'
);

// =============================================================================
// 14. scheduler_validate_queue_item — video platform with asset_path passes
// =============================================================================

$withAsset = ['platform' => 'Instagram Reels', 'scheduled_for' => '2026-06-01 09:00:00', 'caption' => 'hi', 'asset_path' => '/uploads/clip.mp4', 'clip_id' => 0];
$errs = scheduler_validate_queue_item($withAsset);
ptmd_assert_true(count($errs) === 0, 'validate: Instagram Reels with asset_path passes validation');

// =============================================================================
// 15. scheduler_validate_queue_item — X platform without caption warns
// =============================================================================

$xNoCaption = ['platform' => 'X', 'scheduled_for' => '2026-06-01 09:00:00', 'caption' => '', 'asset_path' => '', 'clip_id' => 0];
$errs = scheduler_validate_queue_item($xNoCaption);
ptmd_assert_true(count($errs) > 0, 'validate: X without caption raises warning');
ptmd_assert_true(
    (bool) array_filter($errs, fn($e) => str_contains($e, 'X posts')),
    'validate: X caption warning message present'
);

// =============================================================================
// 16. scheduler_validate_queue_item — X platform with caption passes
// =============================================================================

$xWithCaption = ['platform' => 'X', 'scheduled_for' => '2026-06-01 09:00:00', 'caption' => 'New episode out now! #PTMD', 'asset_path' => '', 'clip_id' => 0];
$errs = scheduler_validate_queue_item($xWithCaption);
ptmd_assert_true(count($errs) === 0, 'validate: X with caption passes validation');

// =============================================================================
// 17. scheduler_auto_fill_item — fills caption from prefix + hashtags
// =============================================================================

/**
 * Minimal mock PDO for auto-fill tests.
 * Simulates a platform preferences row for 'TikTok'.
 */
class AutoFillMockStmt
{
    private string $platform;
    public function __construct(string $p) { $this->platform = $p; }
    public function execute(array $params): void {}
    public function fetch(): array|false
    {
        return $this->platform === 'TikTok' ? [
            'default_content_type'  => 'clip',
            'default_caption_prefix'=> "New PTMD clip dropping now 🎬",
            'default_hashtags'      => '#PTMD #Documentary #TrueCrime',
        ] : false;
    }
}

class AutoFillMockPdo
{
    public function prepare(string $sql): AutoFillMockStmt
    {
        // Extract the :p placeholder binding to know which platform to return
        return new AutoFillMockStmt('TikTok');
    }
}

$autoFillPdo = new AutoFillMockPdo();

// Empty caption + generic content_type → both should be filled
$itemToFill = [
    'platform'     => 'TikTok',
    'content_type' => 'general',
    'caption'      => '',
    'asset_path'   => null,
    'clip_id'      => 0,
];
$filled = scheduler_auto_fill_item($autoFillPdo, $itemToFill);
ptmd_assert_same($filled['content_type'], 'clip', 'auto_fill: content_type set from platform pref');
ptmd_assert_true(
    str_contains($filled['caption'], 'PTMD clip'),
    'auto_fill: caption prefix injected'
);
ptmd_assert_true(
    str_contains($filled['caption'], '#PTMD'),
    'auto_fill: hashtags injected into caption'
);

// =============================================================================
// 18. scheduler_auto_fill_item — does not overwrite existing values
// =============================================================================

$existingCaption = [
    'platform'     => 'TikTok',
    'content_type' => 'teaser',
    'caption'      => 'My custom caption.',
    'asset_path'   => null,
    'clip_id'      => 0,
];
$filledExisting = scheduler_auto_fill_item($autoFillPdo, $existingCaption);
ptmd_assert_same($filledExisting['caption'],      'My custom caption.', 'auto_fill: existing caption not overwritten');
ptmd_assert_same($filledExisting['content_type'], 'teaser',             'auto_fill: existing content_type not overwritten');

// =============================================================================
// 19. scheduler_auto_fill_item — no-op when no pref row for platform
// =============================================================================

/**
 * Returns false (no pref row) for any platform.
 */
class NoPrefMockStmt
{
    public function execute(array $params): void {}
    public function fetch(): false { return false; }
}

class NoPrefMockPdo
{
    public function prepare(string $sql): NoPrefMockStmt { return new NoPrefMockStmt(); }
}

$noPrefPdo = new NoPrefMockPdo();
$unchanged = scheduler_auto_fill_item($noPrefPdo, $itemToFill);
ptmd_assert_same($unchanged['content_type'], 'general', 'auto_fill: content_type unchanged when no pref row');
ptmd_assert_same($unchanged['caption'],      '',        'auto_fill: caption unchanged when no pref row');

// =============================================================================
// 20. scheduler_expand_schedule_to_queue — content_auto uses auto-fill
// =============================================================================

/**
 * Mock PDO that:
 *  - Returns empty list for existing queue dates (SELECT DATE).
 *  - Returns a platform pref row for auto-fill (SELECT default_content_type…).
 *  - Accepts INSERT and UPDATE without error.
 */
class ContentAutoMockStmt
{
    private string $sql;
    public function __construct(string $sql) { $this->sql = $sql; }
    public function execute(array $p = []): void {}
    public function fetchAll(): array
    {
        // existing dates query → no existing items
        return [];
    }
    public function fetch(): array|false
    {
        // platform prefs query
        return [
            'default_content_type'   => 'short-clip',
            'default_caption_prefix' => 'Watch this 🔍',
            'default_hashtags'       => '#PTMD',
        ];
    }
    public function bindValue(mixed ...$args): void {}
}

class ContentAutoMockPdo
{
    public array $insertedCaptions = [];
    public array $insertedStatuses = [];

    public function prepare(string $sql): ContentAutoMockStmt { return new ContentAutoMockStmt($sql); }
}

// Use a recording mock so we can inspect inserted rows
class ContentAutoRecordingStmt
{
    public string $sql;
    private ContentAutoRecordingPdo $pdo;

    public function __construct(string $sql, ContentAutoRecordingPdo $pdo)
    {
        $this->sql = $sql;
        $this->pdo = $pdo;
    }

    public function execute(array $params = []): void
    {
        if (str_contains($this->sql, 'INSERT INTO social_post_queue')) {
            $this->pdo->captionLog[] = $params['caption'] ?? null;
            $this->pdo->statusLog[]  = $params['status']  ?? null;
        }
    }

    public function fetchAll(): array { return []; }
    public function fetch(): array|false
    {
        return [
            'default_content_type'   => 'short-clip',
            'default_caption_prefix' => 'Watch this 🔍',
            'default_hashtags'       => '#PTMD',
        ];
    }
    public function bindValue(mixed ...$args): void {}
}

class ContentAutoRecordingPdo
{
    public array $captionLog = [];
    public array $statusLog  = [];

    public function prepare(string $sql): ContentAutoRecordingStmt
    {
        return new ContentAutoRecordingStmt($sql, $this);
    }
}

$recPdo   = new ContentAutoRecordingPdo();
$capSched = [
    'id'              => 10,
    'platform'        => 'TikTok',
    'content_type'    => 'general',
    'day_of_week'     => 'Monday',
    'post_time'       => '10:00:00',
    'timezone'        => 'UTC',
    'recurrence_type' => 'weekly',
];

// Run with contentAuto = true, horizon 8 days (≈ 1 Monday occurrence)
$from8   = new \DateTimeImmutable('2026-04-13 00:00:00', new \DateTimeZone('UTC')); // a Monday
$capResult = scheduler_expand_schedule_to_queue($recPdo, $capSched, 8, false, $from8, true);

ptmd_assert_true($capResult['generated'] >= 1, 'content_auto: at least one item generated');
if (!empty($recPdo->captionLog)) {
    ptmd_assert_true(
        str_contains($recPdo->captionLog[0] ?? '', 'Watch this'),
        'content_auto: auto-filled caption used in INSERT'
    );
}

// =============================================================================
// 21. scheduler_expand_schedule_to_queue — contentAuto=false leaves caption empty
// =============================================================================

$plainPdo = new ContentAutoRecordingPdo();
scheduler_expand_schedule_to_queue($plainPdo, $capSched, 8, false, $from8, false);
if (!empty($plainPdo->captionLog)) {
    ptmd_assert_same(
        $plainPdo->captionLog[0],
        '',
        'content_auto disabled: caption stays empty in INSERT'
    );
}

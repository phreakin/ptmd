<?php
/**
 * PTMD — Scheduler Library  (inc/scheduler.php)
 *
 * Pure functions used by:
 *  - web/api/scheduler.php  (HTTP endpoint invoked by cron)
 *  - web/tests/scheduler_test.php  (unit tests)
 *
 * Design notes:
 *  - All DB-touching functions accept a PDO parameter so tests can inject fakes.
 *  - Token verification reads from site_settings via get_db() — keep that call
 *    inside functions that need it.
 *  - Idempotency: queue rows are deduplicated by (schedule_id, DATE(scheduled_for)).
 *  - Lock: a timestamp in site_settings key `scheduler_lock_expires` acts as a
 *    lease.  If the value is empty or is a past datetime, the lock is free.
 */

// ---------------------------------------------------------------------------
// Token / security
// ---------------------------------------------------------------------------

/**
 * Verify that the provided Bearer token matches the stored scheduler secret.
 * Returns false when the secret is not configured (scheduler can't run).
 */
function scheduler_verify_token(string $token): bool
{
    if ($token === '') {
        return false;
    }

    $pdo = get_db();
    if (!$pdo) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT setting_value FROM site_settings WHERE setting_key = :k LIMIT 1'
    );
    $stmt->execute(['k' => 'scheduler_secret']);
    $secret = (string) ($stmt->fetchColumn() ?: '');

    if ($secret === '') {
        return false;
    }

    return hash_equals($secret, $token);
}

/**
 * Verify that the caller IP is in the allowlist (if one is configured).
 * An empty allowlist means "any IP is allowed".
 * $callerIp should be REMOTE_ADDR.
 */
function scheduler_verify_ip(string $callerIp): bool
{
    $pdo = get_db();
    if (!$pdo) {
        return true; // no DB = no restriction check; token check still applies
    }

    $stmt = $pdo->prepare(
        'SELECT setting_value FROM site_settings WHERE setting_key = :k LIMIT 1'
    );
    $stmt->execute(['k' => 'scheduler_ip_allowlist']);
    $raw = trim((string) ($stmt->fetchColumn() ?: ''));

    if ($raw === '') {
        return true; // no allowlist configured
    }

    $allowed = array_filter(array_map('trim', explode(',', $raw)));
    return in_array($callerIp, $allowed, true);
}

// ---------------------------------------------------------------------------
// Lock / lease mechanism  (prevents concurrent runs)
// ---------------------------------------------------------------------------

/**
 * Acquire a scheduler run lease for $ttlSeconds seconds.
 * Returns true if the lock was obtained, false if another run holds it.
 */
function scheduler_acquire_lock(PDO $pdo, int $ttlSeconds = 300): bool
{
    $stmt = $pdo->prepare(
        'SELECT setting_value FROM site_settings WHERE setting_key = :k LIMIT 1'
    );
    $stmt->execute(['k' => 'scheduler_lock_expires']);
    $lockExpires = (string) ($stmt->fetchColumn() ?: '');

    $now = time();

    if ($lockExpires !== '' && strtotime($lockExpires) > $now) {
        return false; // lock held by another process
    }

    $newExpiry = date('Y-m-d H:i:s', $now + $ttlSeconds);
    $pdo->prepare(
        'UPDATE site_settings SET setting_value = :v, updated_at = NOW()
         WHERE setting_key = :k'
    )->execute(['v' => $newExpiry, 'k' => 'scheduler_lock_expires']);

    return true;
}

/**
 * Release the scheduler lock immediately.
 */
function scheduler_release_lock(PDO $pdo): void
{
    $pdo->prepare(
        'UPDATE site_settings SET setting_value = :v, updated_at = NOW()
         WHERE setting_key = :k'
    )->execute(['v' => '', 'k' => 'scheduler_lock_expires']);
}

// ---------------------------------------------------------------------------
// Recurrence expansion
// ---------------------------------------------------------------------------

/**
 * Return the next N occurrence timestamps for a schedule rule, starting from
 * $fromDate and spanning up to $horizonDays days.
 *
 * $schedule must contain: day_of_week, post_time, timezone, recurrence_type
 *
 * Returns an array of DateTime objects in the schedule's timezone, each
 * already converted to UTC for storage.
 */
function scheduler_expand_occurrences(array $schedule, \DateTimeImmutable $fromDate, int $horizonDays): array
{
    $tz    = new \DateTimeZone($schedule['timezone'] ?? 'America/Phoenix');
    $utcTz = new \DateTimeZone('UTC');

    $horizon = $fromDate->modify('+' . $horizonDays . ' days');
    $results = [];

    $recurrence = strtolower($schedule['recurrence_type'] ?? 'weekly');

    switch ($recurrence) {
        case 'daily':
            $results = _scheduler_expand_daily($schedule, $fromDate, $horizon, $tz, $utcTz);
            break;

        case 'monthly':
            $results = _scheduler_expand_monthly($schedule, $fromDate, $horizon, $tz, $utcTz);
            break;

        case 'weekly':
        default:
            $results = _scheduler_expand_weekly($schedule, $fromDate, $horizon, $tz, $utcTz);
            break;
    }

    return $results;
}

function _scheduler_expand_weekly(
    array $schedule,
    \DateTimeImmutable $from,
    \DateTimeImmutable $horizon,
    \DateTimeZone $tz,
    \DateTimeZone $utcTz
): array {
    $dayMap = [
        'sunday'    => 0,
        'monday'    => 1,
        'tuesday'   => 2,
        'wednesday' => 3,
        'thursday'  => 4,
        'friday'    => 5,
        'saturday'  => 6,
    ];

    $targetDow  = $dayMap[strtolower(trim($schedule['day_of_week'] ?? 'monday'))] ?? 1;
    $postTime   = trim($schedule['post_time'] ?? '09:00:00');
    $results    = [];

    // Find first occurrence on or after $from
    $cursor = new \DateTime($from->format('Y-m-d') . ' ' . $postTime, $tz);
    $curDow = (int) $cursor->format('w'); // 0=Sunday
    $diff   = ($targetDow - $curDow + 7) % 7;
    if ($diff > 0) {
        $cursor->modify('+' . $diff . ' days');
    }

    $horizonUtc = \DateTime::createFromImmutable($horizon)->setTimezone($utcTz);

    while (true) {
        $utc = clone $cursor;
        $utc->setTimezone($utcTz);

        if ($utc > $horizonUtc) {
            break;
        }

        $results[] = $utc->format('Y-m-d H:i:s');
        $cursor->modify('+7 days');
    }

    return $results;
}

function _scheduler_expand_daily(
    array $schedule,
    \DateTimeImmutable $from,
    \DateTimeImmutable $horizon,
    \DateTimeZone $tz,
    \DateTimeZone $utcTz
): array {
    $postTime   = trim($schedule['post_time'] ?? '09:00:00');
    $results    = [];

    $cursor     = new \DateTime($from->format('Y-m-d') . ' ' . $postTime, $tz);
    $horizonUtc = \DateTime::createFromImmutable($horizon)->setTimezone($utcTz);

    while (true) {
        $utc = clone $cursor;
        $utc->setTimezone($utcTz);

        if ($utc > $horizonUtc) {
            break;
        }

        $results[] = $utc->format('Y-m-d H:i:s');
        $cursor->modify('+1 day');
    }

    return $results;
}

function _scheduler_expand_monthly(
    array $schedule,
    \DateTimeImmutable $from,
    \DateTimeImmutable $horizon,
    \DateTimeZone $tz,
    \DateTimeZone $utcTz
): array {
    // Monthly fires on the same calendar day as the schedule's creation.
    // We use the day stored in day_of_week as a day-of-month number (1-28)
    // when recurrence_type = monthly.  If it's a weekday name, fall back to 1.
    $rawDay  = trim($schedule['day_of_week'] ?? '1');
    $dayNum  = is_numeric($rawDay) ? max(1, min(28, (int) $rawDay)) : 1;
    $postTime = trim($schedule['post_time'] ?? '09:00:00');
    $results  = [];

    $cursor = new \DateTime($from->format('Y-m') . '-' . str_pad((string) $dayNum, 2, '0', STR_PAD_LEFT) . ' ' . $postTime, $tz);
    if ($cursor < \DateTime::createFromImmutable($from)) {
        $cursor->modify('+1 month');
    }

    $horizonUtc = \DateTime::createFromImmutable($horizon)->setTimezone($utcTz);

    while (true) {
        $utc = clone $cursor;
        $utc->setTimezone($utcTz);

        if ($utc > $horizonUtc) {
            break;
        }

        $results[] = $utc->format('Y-m-d H:i:s');
        $cursor->modify('+1 month');
    }

    return $results;
}

// ---------------------------------------------------------------------------
// Queue generation from schedule rules
// ---------------------------------------------------------------------------

/**
 * For one schedule rule, generate all missing queue items within the horizon.
 *
 * Idempotency: we deduplicate by (schedule_id, DATE(scheduled_for, UTC)) so
 * running this twice for the same window never inserts duplicates.
 *
 * Returns an array with keys: generated (int), skipped (int), errors (string[]).
 */
function scheduler_expand_schedule_to_queue(
    object $pdo,
    array $schedule,
    int $horizonDays,
    bool $dryRun = false,
    ?\DateTimeImmutable $from = null,
    bool $contentAuto = false
): array {
    $from = $from ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    $occurrences = scheduler_expand_occurrences($schedule, $from, $horizonDays);

    $generated = 0;
    $skipped   = 0;
    $errors    = [];

    // Load existing queue dates for this schedule to check duplicates
    $existingStmt = $pdo->prepare(
        'SELECT DATE(scheduled_for) AS d
         FROM social_post_queue
         WHERE schedule_id = :sid
           AND status NOT IN ("canceled","failed")'
    );
    $existingStmt->execute(['sid' => (int) $schedule['id']]);
    $existingDates = array_flip(array_column($existingStmt->fetchAll(), 'd'));

    foreach ($occurrences as $scheduledFor) {
        $dateKey = substr($scheduledFor, 0, 10); // YYYY-MM-DD

        if (isset($existingDates[$dateKey])) {
            $skipped++;
            continue;
        }

        if ($dryRun) {
            $generated++;
            $existingDates[$dateKey] = true; // prevent dry-run duplicates within same pass
            continue;
        }

        // Build the candidate item
        $candidate = [
            'episode_id'    => null,
            'clip_id'       => null,
            'platform'      => $schedule['platform'],
            'content_type'  => $schedule['content_type'],
            'caption'       => '',
            'asset_path'    => null,
            'scheduled_for' => $scheduledFor,
            'schedule_id'   => (int) $schedule['id'],
            'auto_generated'=> 1,
        ];

        // Phase 4: auto-fill from platform preferences when enabled
        $validationWarnings = [];
        if ($contentAuto) {
            $candidate = scheduler_auto_fill_item($pdo, $candidate);
            $validationWarnings = scheduler_validate_queue_item($candidate);
        }

        // Items with validation warnings start as 'draft' for editor review;
        // clean items are inserted as 'queued' so the scheduler can dispatch them.
        $insertStatus = empty($validationWarnings) ? 'queued' : 'draft';
        $lastError    = empty($validationWarnings) ? null : implode(' | ', $validationWarnings);

        try {
            $pdo->prepare(
                'INSERT INTO social_post_queue
                 (episode_id, clip_id, platform, content_type, caption, asset_path,
                  scheduled_for, status, schedule_id, auto_generated, retry_count,
                  last_error, created_at, updated_at)
                 VALUES
                 (:eid, NULL, :platform, :ct, :caption, NULL,
                  :sched, :status, :sid, 1, 0,
                  :last_error, NOW(), NOW())'
            )->execute([
                'eid'        => null,
                'platform'   => $candidate['platform'],
                'ct'         => $candidate['content_type'],
                'caption'    => $candidate['caption'],
                'sched'      => $scheduledFor,
                'status'     => $insertStatus,
                'sid'        => (int) $schedule['id'],
                'last_error' => $lastError,
            ]);
            $generated++;
            $existingDates[$dateKey] = true;

            if (!empty($validationWarnings)) {
                $errors[] = 'Review needed for ' . $scheduledFor . ': ' . implode('; ', $validationWarnings);
            }
        } catch (\PDOException $e) {
            $errors[] = 'Insert failed for ' . $scheduledFor . ': ' . $e->getMessage();
        }
    }

    // Update schedule's last_generated_at + last_run_status
    if (!$dryRun) {
        $runStatus = empty($errors) ? 'ok' : (array_filter($errors, fn($e) => !str_starts_with($e, 'Review')) === [] ? 'review' : 'error');
        $pdo->prepare(
            'UPDATE social_post_schedules
             SET last_generated_at = NOW(), last_run_status = :s, updated_at = NOW()
             WHERE id = :id'
        )->execute(['s' => $runStatus, 'id' => (int) $schedule['id']]);
    }

    return compact('generated', 'skipped', 'errors');
}

// ---------------------------------------------------------------------------
// Dispatch (process due queue items)
// ---------------------------------------------------------------------------

/**
 * Fetch queue items that are due for dispatch.
 * "Due" = status in ('queued','scheduled') AND scheduled_for <= NOW()
 *          AND (retry_after IS NULL OR retry_after <= NOW())
 *
 * Items are locked by changing status to 'processing' atomically inside the
 * caller loop so concurrent runs don't double-dispatch.
 */
function scheduler_get_due_items(PDO $pdo, int $limit = 50): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM social_post_queue
         WHERE status IN ("queued","scheduled")
           AND scheduled_for <= NOW()
           AND (retry_after IS NULL OR retry_after <= NOW())
         ORDER BY scheduled_for ASC
         LIMIT :lim'
    );
    $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Atomically claim one queue item for processing.
 * Returns true if this process successfully set status to 'processing'.
 * Returns false if another process already grabbed it.
 */
function scheduler_claim_item(PDO $pdo, int $itemId): bool
{
    $stmt = $pdo->prepare(
        'UPDATE social_post_queue
         SET status = "processing", updated_at = NOW()
         WHERE id = :id
           AND status IN ("queued","scheduled")'
    );
    $stmt->execute(['id' => $itemId]);
    return $stmt->rowCount() === 1;
}

/**
 * Dispatch a single queue item (that has already been claimed as 'processing').
 *
 * On dry-run: logs the *intent* but does not call dispatch_social_post().
 * Returns an array: ['ok' => bool, 'external_post_id' => ?string, 'error' => ?string]
 */
function scheduler_dispatch_item(PDO $pdo, array $item, bool $dryRun = false): array
{
    if ($dryRun) {
        // Revert to queued so next real run picks it up
        $pdo->prepare(
            'UPDATE social_post_queue SET status = "queued", updated_at = NOW() WHERE id = :id'
        )->execute(['id' => (int) $item['id']]);
        return ['ok' => true, 'external_post_id' => null, 'error' => null];
    }

    $result = dispatch_social_post($item);
    // dispatch_social_post() already writes the log row + updates status in DB.
    return $result;
}

// ---------------------------------------------------------------------------
// Retry policy
// ---------------------------------------------------------------------------

/**
 * Determine whether an item should be retried.
 * Returns true when retry_count < max_retries.
 */
function scheduler_should_retry(array $item, int $maxRetries): bool
{
    return (int) ($item['retry_count'] ?? 0) < $maxRetries;
}

/**
 * Mark a failed item for retry.
 * Sets status back to 'queued', increments retry_count, and sets retry_after
 * using exponential backoff: intervalMin * 2^(retry_count) minutes.
 *
 * If retry_count has reached maxRetries, marks the item terminal 'failed'
 * with a clear last_error message.
 */
function scheduler_mark_retry_or_fail(
    PDO $pdo,
    int $itemId,
    string $error,
    int $maxRetries,
    int $intervalMin
): void {
    $stmt = $pdo->prepare(
        'SELECT retry_count FROM social_post_queue WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $itemId]);
    $currentRetry = (int) ($stmt->fetchColumn() ?: 0);

    if ($currentRetry >= $maxRetries) {
        // Terminal failure
        $pdo->prepare(
            'UPDATE social_post_queue
             SET status = "failed",
                 last_error = :err,
                 updated_at = NOW()
             WHERE id = :id'
        )->execute([
            'err' => '[Terminal after ' . $currentRetry . ' retries] ' . $error,
            'id'  => $itemId,
        ]);
        return;
    }

    $backoffMin  = $intervalMin * (2 ** $currentRetry);
    $retryAfter  = date('Y-m-d H:i:s', time() + $backoffMin * 60);

    $pdo->prepare(
        'UPDATE social_post_queue
         SET status      = "queued",
             retry_count = retry_count + 1,
             retry_after = :ra,
             last_error  = :err,
             updated_at  = NOW()
         WHERE id = :id'
    )->execute([
        'ra'  => $retryAfter,
        'err' => '[Retry ' . ($currentRetry + 1) . '/' . $maxRetries . '] ' . $error,
        'id'  => $itemId,
    ]);
}

// ---------------------------------------------------------------------------
// Full run orchestration
// ---------------------------------------------------------------------------

/**
 * Run the full scheduler pass:
 *   1. Acquire lock
 *   2. Generate queue items from active schedule rules
 *   3. Dispatch due items
 *   4. Release lock
 *
 * Returns a structured result array suitable for JSON output.
 */
function scheduler_run(PDO $pdo, bool $dryRun = false): array
{
    $horizonDays   = max(1, (int) _scheduler_setting($pdo, 'scheduler_horizon_days', '30'));
    $maxRetries    = max(0, (int) _scheduler_setting($pdo, 'scheduler_max_retries', '3'));
    $retryInterval = max(1, (int) _scheduler_setting($pdo, 'scheduler_retry_interval', '15'));
    $contentAuto   = _scheduler_setting($pdo, 'scheduler_content_auto', '0') === '1';

    // ── 1. Lock ───────────────────────────────────────────────────────────────
    if (!$dryRun && !scheduler_acquire_lock($pdo)) {
        return [
            'ok'    => false,
            'error' => 'Scheduler already running (lock held). Try again in a few minutes.',
        ];
    }

    $log = [
        'dry_run'    => $dryRun,
        'started_at' => date('Y-m-d H:i:s'),
        'generation' => [],
        'dispatch'   => [],
        'errors'     => [],
    ];

    try {
        // ── 2. Generate queue items from active schedule rules ────────────────
        $schedules = $pdo->query(
            'SELECT * FROM social_post_schedules WHERE is_active = 1 ORDER BY id'
        )->fetchAll();

        foreach ($schedules as $schedule) {
            $genResult = scheduler_expand_schedule_to_queue($pdo, $schedule, $horizonDays, $dryRun, null, $contentAuto);
            $log['generation'][] = [
                'schedule_id'   => (int) $schedule['id'],
                'platform'      => $schedule['platform'],
                'day_of_week'   => $schedule['day_of_week'],
                'post_time'     => $schedule['post_time'],
                'generated'     => $genResult['generated'],
                'skipped'       => $genResult['skipped'],
                'errors'        => $genResult['errors'],
            ];
            if (!empty($genResult['errors'])) {
                $log['errors'] = array_merge($log['errors'], $genResult['errors']);
            }
        }

        // ── 3. Dispatch due items ─────────────────────────────────────────────
        $dueItems = scheduler_get_due_items($pdo);

        foreach ($dueItems as $item) {
            $itemId = (int) $item['id'];

            if (!$dryRun && !scheduler_claim_item($pdo, $itemId)) {
                // Already grabbed by another process
                continue;
            }

            $dispatchResult = scheduler_dispatch_item($pdo, $item, $dryRun);

            $dispatchLog = [
                'queue_id'   => $itemId,
                'platform'   => $item['platform'],
                'scheduled'  => $item['scheduled_for'],
                'ok'         => $dispatchResult['ok'],
                'error'      => $dispatchResult['error'] ?? null,
            ];

            if (!$dispatchResult['ok'] && !$dryRun) {
                $err = (string) ($dispatchResult['error'] ?? 'dispatch failed');
                scheduler_mark_retry_or_fail($pdo, $itemId, $err, $maxRetries, $retryInterval);
                $log['errors'][] = 'Queue #' . $itemId . ': ' . $err;
            }

            $log['dispatch'][] = $dispatchLog;
        }

    } finally {
        // ── 4. Release lock ───────────────────────────────────────────────────
        if (!$dryRun) {
            scheduler_release_lock($pdo);
        }
    }

    $log['finished_at']      = date('Y-m-d H:i:s');
    $log['total_generated']  = array_sum(array_column($log['generation'], 'generated'));
    $log['total_dispatched'] = count($log['dispatch']);
    $log['total_errors']     = count($log['errors']);
    $log['ok']               = $log['total_errors'] === 0;

    return $log;
}

// ---------------------------------------------------------------------------
// Content auto-fill  (Phase 4)
// ---------------------------------------------------------------------------

/**
 * Auto-fill a queue item's caption and content_type from social_platform_preferences.
 *
 * Rules:
 *  - content_type: use platform default if item value is empty or 'general'
 *  - caption: compose from default_caption_prefix + default_hashtags when caption is empty
 *
 * Returns the (possibly updated) item array.  The 'auto_generated' flag is
 * left as-is; callers are responsible for marking the item appropriately.
 */
function scheduler_auto_fill_item(object $pdo, array $item): array
{
    $stmt = $pdo->prepare(
        'SELECT default_content_type, default_caption_prefix, default_hashtags
         FROM social_platform_preferences
         WHERE platform = :p AND is_enabled = 1
         LIMIT 1'
    );
    $stmt->execute(['p' => $item['platform']]);
    $pref = $stmt->fetch();

    if (!$pref) {
        return $item; // no enabled preference row for this platform
    }

    // Auto-fill content_type when blank or generic
    $currentCt = trim((string) ($item['content_type'] ?? ''));
    if (in_array($currentCt, ['', 'general'], true)) {
        $defaultCt = trim((string) ($pref['default_content_type'] ?? ''));
        if ($defaultCt !== '') {
            $item['content_type'] = $defaultCt;
        }
    }

    // Auto-fill caption when empty
    $caption = trim((string) ($item['caption'] ?? ''));
    if ($caption === '') {
        $prefix   = trim((string) ($pref['default_caption_prefix'] ?? ''));
        $hashtags = trim((string) ($pref['default_hashtags']       ?? ''));
        $parts    = array_filter([$prefix, $hashtags]);
        if (!empty($parts)) {
            $item['caption'] = implode("\n\n", $parts);
        }
    }

    return $item;
}

/**
 * Validate a queue item and return an array of human-readable warning strings.
 *
 * An empty return array means the item passed all checks.
 * Warning-level issues (missing video asset, missing caption) do not block
 * insertion but cause the item to be saved as 'draft' for editor review.
 */
function scheduler_validate_queue_item(array $item): array
{
    $errors = [];

    $platform = trim((string) ($item['platform'] ?? ''));
    if ($platform === '') {
        $errors[] = 'Platform is required.';
    }

    if (trim((string) ($item['scheduled_for'] ?? '')) === '') {
        $errors[] = 'scheduled_for is required.';
    }

    // Platforms that require a video asset before posting
    $videoRequired = [
        'YouTube', 'YouTube Shorts', 'TikTok',
        'Instagram Reels', 'Facebook Reels',
    ];
    if (in_array($platform, $videoRequired, true)) {
        $assetPath = trim((string) ($item['asset_path'] ?? ''));
        $clipId    = (int) ($item['clip_id'] ?? 0);
        if ($assetPath === '' && $clipId === 0) {
            $errors[] = $platform . ' posts require a video asset (asset_path or clip_id). Please assign one before posting.';
        }
    }

    // X (Twitter) is caption-first: warn when caption is empty
    if ($platform === 'X' && trim((string) ($item['caption'] ?? '')) === '') {
        $errors[] = 'X posts should have caption text. Please add a caption before posting.';
    }

    return $errors;
}

// ---------------------------------------------------------------------------
// Internal helper
// ---------------------------------------------------------------------------

function _scheduler_setting(PDO $pdo, string $key, string $default): string
{
    $stmt = $pdo->prepare(
        'SELECT setting_value FROM site_settings WHERE setting_key = :k LIMIT 1'
    );
    $stmt->execute(['k' => $key]);
    $val = $stmt->fetchColumn();
    return ($val !== false && (string) $val !== '') ? (string) $val : $default;
}

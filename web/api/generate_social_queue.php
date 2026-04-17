<?php
/**
 * PTMD API — Social Queue Generator
 *
 * POST  { csrf_token, episode_id, [reference_date] }
 *
 * Reads all active social_post_schedules and creates social_post_queue entries
 * anchored to the episode's published_at (or reference_date if provided).
 *
 * Returns JSON: { ok, count, entries[] }
 */

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = get_db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
    exit;
}

if (!is_post()) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$episodeId     = (int) ($_POST['episode_id']     ?? 0);
$referenceDate = trim((string) ($_POST['reference_date'] ?? ''));

if ($episodeId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'episode_id required']);
    exit;
}

$epStmt = $pdo->prepare('SELECT * FROM episodes WHERE id = :id');
$epStmt->execute(['id' => $episodeId]);
$episode = $epStmt->fetch();

if (!$episode) {
    echo json_encode(['ok' => false, 'error' => 'Episode not found']);
    exit;
}

// ── Determine anchor date ─────────────────────────────────────────────────────
if ($referenceDate !== '') {
    try {
        $anchor = new DateTimeImmutable($referenceDate);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => 'Invalid reference_date format']);
        exit;
    }
} elseif (!empty($episode['published_at'])) {
    $anchor = new DateTimeImmutable((string) $episode['published_at']);
} else {
    $anchor = new DateTimeImmutable(); // now
}

// ── Load active schedules ─────────────────────────────────────────────────────
$schedules = $pdo->query(
    'SELECT * FROM social_post_schedules WHERE is_active = 1'
)->fetchAll();

if (empty($schedules)) {
    echo json_encode(['ok' => true, 'count' => 0, 'entries' => [], 'message' => 'No active schedules found.']);
    exit;
}

// ── Helper: next occurrence of $dayName on/after $from ───────────────────────
function next_schedule_date(DateTimeImmutable $from, string $dayName, string $time): string
{
    static $dayOrder = [
        'Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3,
        'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6,
    ];

    $targetDay  = $dayOrder[$dayName] ?? 0;
    $currentDay = (int) $from->format('w');
    $diff       = ($targetDay - $currentDay + 7) % 7;

    if ($diff === 0) {
        $diff = 7; // at least one full week ahead of anchor
    }

    $date = $from->modify("+{$diff} days")->format('Y-m-d');

    return $date . ' ' . $time;
}

// ── Insert queue entries ──────────────────────────────────────────────────────
$insertStmt = $pdo->prepare(
    'INSERT INTO social_post_queue
     (episode_id, platform, content_type, caption, asset_path, scheduled_for, status, created_at, updated_at)
     VALUES (:eid, :platform, :ct, :caption, :asset, :sched, "queued", NOW(), NOW())'
);

$created = [];
foreach ($schedules as $sched) {
    $scheduledFor = next_schedule_date($anchor, $sched['day_of_week'], $sched['post_time']);

    $insertStmt->execute([
        'eid'      => $episodeId,
        'platform' => $sched['platform'],
        'ct'       => $sched['content_type'],
        'caption'  => '',                   // admin fills in captions manually
        'asset'    => '',                   // admin picks asset in Social Queue
        'sched'    => $scheduledFor,
    ]);

    $created[] = [
        'platform'      => $sched['platform'],
        'content_type'  => $sched['content_type'],
        'scheduled_for' => $scheduledFor,
    ];
}

echo json_encode([
    'ok'      => true,
    'count'   => count($created),
    'entries' => $created,
]);

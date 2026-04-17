<?php
/**
 * PTMD API — Apply Overlays Batch Processor
 *
 * POST  — Submit a new batch job (overlay + clip paths + settings)
 * GET   — Poll status of an existing batch job by ?job_id=N
 *
 * Batch processing runs synchronously for small batches (≤ 5 clips).
 * For larger batches it queues items and returns immediately; a
 * background cron or manual trigger processes remaining items.
 *
 * Security: requires admin session.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/video_processor.php';

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

// ── GET: Poll job status ───────────────────────────────────────────────────────
if (!is_post()) {
    $jobId = isset($_GET['job_id']) ? (int) $_GET['job_id'] : 0;

    if ($jobId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Missing job_id']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM overlay_batch_jobs WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $jobId]);
    $job = $stmt->fetch();

    if (!$job) {
        echo json_encode(['ok' => false, 'error' => 'Job not found']);
        exit;
    }

    echo json_encode([
        'ok'     => true,
        'job_id' => (int) $job['id'],
        'status' => $job['status'],
        'total'  => (int) $job['total_items'],
        'done'   => (int) $job['done_items'],
    ]);
    exit;
}

// ── POST: Create batch job ─────────────────────────────────────────────────────
if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$overlayPath = trim((string) ($_POST['overlay_path'] ?? ''));
$position    = trim((string) ($_POST['position']     ?? 'bottom-right'));
$opacity     = max(0.0, min(1.0, (float) ($_POST['opacity'] ?? 1.0)));
$scale       = max(5, min(100, (int)   ($_POST['scale']   ?? 30)));
$label       = trim((string) ($_POST['label']        ?? 'Untitled Batch'));
$assetPaths  = $_POST['asset_paths'] ?? ($_POST['clip_paths'] ?? []);

$allowedPositions = ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'center', 'full'];
if (!in_array($position, $allowedPositions, true)) {
    $position = 'bottom-right';
}

if ($overlayPath === '') {
    echo json_encode(['ok' => false, 'error' => 'No overlay selected']);
    exit;
}

if (!is_array($assetPaths) || count($assetPaths) === 0) {
    echo json_encode(['ok' => false, 'error' => 'No assets selected']);
    exit;
}

// Sanitize overlay source
$allowedOverlayPrefixes = ['/uploads/', '/assets/brand/'];

function is_safe_path(string $path, array $prefixes): bool
{
    foreach ($prefixes as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }
    return false;
}

if (!is_safe_path($overlayPath, $allowedOverlayPrefixes)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid overlay path']);
    exit;
}

$safeAssets = array_filter($assetPaths, fn($p) => is_safe_path(trim((string) $p), ['/uploads/']));
$safeAssets = array_values($safeAssets);

if (count($safeAssets) === 0) {
    echo json_encode(['ok' => false, 'error' => 'No valid asset paths provided']);
    exit;
}

// Limit to 20 assets per batch
$safeAssets = array_slice($safeAssets, 0, 20);

// ── Create batch job row ──────────────────────────────────────────────────────
$jobStmt = $pdo->prepare(
    'INSERT INTO overlay_batch_jobs
     (label, overlay_path, position, opacity, scale, status, total_items, done_items, created_by, created_at, updated_at)
     VALUES (:label, :overlay, :position, :opacity, :scale, "pending", :total, 0, :user, NOW(), NOW())'
);

$jobStmt->execute([
    'label'    => $label,
    'overlay'  => $overlayPath,
    'position' => $position,
    'opacity'  => number_format($opacity, 2, '.', ''),
    'scale'    => $scale,
    'total'    => count($safeAssets),
    'user'     => (int) ($_SESSION['admin_user_id'] ?? 0),
]);

$jobId = (int) $pdo->lastInsertId();

// ── Insert batch items ────────────────────────────────────────────────────────
$itemStmt = $pdo->prepare(
    'INSERT INTO overlay_batch_items (batch_job_id, source_path, status, created_at, updated_at)
     VALUES (:job_id, :source, "pending", NOW(), NOW())'
);

foreach ($safeAssets as $assetPath) {
    // source_path is relative to /uploads — strip the /uploads/ prefix
    $rel = ltrim(str_replace('/uploads/', '', trim((string) $assetPath)), '/');
    $itemStmt->execute(['job_id' => $jobId, 'source' => $rel]);
}

// ── Process synchronously if small batch ─────────────────────────────────────
$SYNC_LIMIT = 5;

if (count($safeAssets) <= $SYNC_LIMIT) {
    $pdo->prepare(
        'UPDATE overlay_batch_jobs SET status = "processing", updated_at = NOW() WHERE id = :id'
    )->execute(['id' => $jobId]);

    $jobRow = $pdo->prepare('SELECT * FROM overlay_batch_jobs WHERE id = :id');
    $jobRow->execute(['id' => $jobId]);
    $job = $jobRow->fetch();

    $items = $pdo->prepare(
        'SELECT * FROM overlay_batch_items WHERE batch_job_id = :jid AND status = "pending"'
    );
    $items->execute(['jid' => $jobId]);

    foreach ($items->fetchAll() as $item) {
        process_batch_item($pdo, $item, $job);
    }

    // Check final status
    $failCountStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM overlay_batch_items WHERE batch_job_id = :jid AND status = "failed"'
    );
    $failCountStmt->execute(['jid' => $jobId]);
    $failCount = (int) $failCountStmt->fetchColumn();

    $finalStatus = $failCount === 0 ? 'completed' : 'failed';
    $pdo->prepare(
        'UPDATE overlay_batch_jobs SET status = :status, updated_at = NOW() WHERE id = :id'
    )->execute(['status' => $finalStatus, 'id' => $jobId]);
} else {
    // Large batch: mark as queued — cron will process remaining items
    $pdo->prepare(
        'UPDATE overlay_batch_jobs SET status = "processing", updated_at = NOW() WHERE id = :id'
    )->execute(['id' => $jobId]);

    // Process the first 2 synchronously so progress starts immediately
    $firstItems = $pdo->query(
        "SELECT * FROM overlay_batch_items WHERE batch_job_id = {$jobId} AND status = 'pending' LIMIT 2"
    )->fetchAll();

    $jobRow2 = $pdo->query("SELECT * FROM overlay_batch_jobs WHERE id = {$jobId}")->fetch();
    foreach ($firstItems as $item) {
        process_batch_item($pdo, $item, $jobRow2);
    }
}

echo json_encode([
    'ok'         => true,
    'job_id'     => $jobId,
    'item_count' => count($safeAssets),
    'message'    => 'Batch job created and processing.',
]);

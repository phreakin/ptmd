<?php
/**
 * PTMD API — Edit Jobs
 *
 * GET  (no params)         — List all edit jobs (admin)
 * GET  ?job_id=N           — Return job detail + outputs
 * POST (_action=create)    — Create a new edit job + outputs
 * POST (_action=retry)     — Retry a failed output (?output_id=N)
 * POST (_action=cancel)    — Cancel a job (?job_id=N)
 * POST (_action=run_worker)— Trigger the background worker
 *
 * Security: requires admin session + CSRF on all mutating actions.
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

// ── GET ───────────────────────────────────────────────────────────────────────
if (!is_post()) {
    $jobId = isset($_GET['job_id']) ? (int) $_GET['job_id'] : 0;

    if ($jobId > 0) {
        // Single job detail
        $stmt = $pdo->prepare(
            'SELECT ej.*, u.username AS created_by_name
             FROM edit_jobs ej
             LEFT JOIN users u ON u.id = ej.created_by
             WHERE ej.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            echo json_encode(['ok' => false, 'error' => 'Job not found']);
            exit;
        }

        $outStmt = $pdo->prepare(
            'SELECT * FROM edit_job_outputs WHERE job_id = :jid ORDER BY id'
        );
        $outStmt->execute(['jid' => $jobId]);
        $outputs = $outStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok'      => true,
            'job'     => $job,
            'outputs' => $outputs,
        ]);
        exit;
    }

    // List all jobs
    $jobs = $pdo->query(
        'SELECT ej.id, ej.label, ej.status, ej.source_path, ej.caption_mode,
                ej.platforms_json, ej.created_at, u.username AS created_by_name,
                COUNT(ejo.id) AS total_outputs,
                SUM(ejo.status = "done") AS done_outputs,
                SUM(ejo.status = "failed") AS failed_outputs
         FROM edit_jobs ej
         LEFT JOIN users u ON u.id = ej.created_by
         LEFT JOIN edit_job_outputs ejo ON ejo.job_id = ej.id
         GROUP BY ej.id
         ORDER BY ej.created_at DESC
         LIMIT 100'
    )->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'jobs' => $jobs]);
    exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────
if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action = trim((string) ($_POST['_action'] ?? 'create'));

// ── POST: run_worker ──────────────────────────────────────────────────────────
if ($action === 'run_worker') {
    $maxOutputs = max(1, min(200, (int) ($_POST['max_outputs'] ?? 50)));
    $maxJobs    = max(0, min(20,  (int) ($_POST['max_jobs']    ?? 0)));

    $summary = run_edit_job_worker($pdo, $maxJobs, $maxOutputs);

    echo json_encode([
        'ok'      => true,
        'message' => sprintf(
            'Worker finished: %d outputs processed (%d failed) across %d job(s).',
            $summary['processed'],
            $summary['failed'],
            $summary['jobs']
        ),
        'summary' => $summary,
    ]);
    exit;
}

// ── POST: cancel ──────────────────────────────────────────────────────────────
if ($action === 'cancel') {
    $jobId = (int) ($_POST['job_id'] ?? 0);
    if ($jobId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Missing job_id']);
        exit;
    }

    $pdo->prepare(
        'UPDATE edit_jobs SET status = "canceled", updated_at = NOW()
         WHERE id = :id AND status IN ("pending","processing")'
    )->execute(['id' => $jobId]);

    // Also cancel pending outputs
    $pdo->prepare(
        'UPDATE edit_job_outputs SET status = "failed", error_message = "Job canceled",
         updated_at = NOW()
         WHERE job_id = :jid AND status = "pending"'
    )->execute(['jid' => $jobId]);

    echo json_encode(['ok' => true, 'message' => 'Job canceled.']);
    exit;
}

// ── POST: retry ───────────────────────────────────────────────────────────────
if ($action === 'retry') {
    $outputId = (int) ($_POST['output_id'] ?? 0);
    if ($outputId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Missing output_id']);
        exit;
    }

    $outStmt = $pdo->prepare(
        'SELECT ejo.*, ej.max_retries, ej.status AS job_status
         FROM edit_job_outputs ejo
         JOIN edit_jobs ej ON ej.id = ejo.job_id
         WHERE ejo.id = :id LIMIT 1'
    );
    $outStmt->execute(['id' => $outputId]);
    $output = $outStmt->fetch(PDO::FETCH_ASSOC);

    if (!$output) {
        echo json_encode(['ok' => false, 'error' => 'Output not found']);
        exit;
    }

    if ((int) $output['retry_count'] >= (int) $output['max_retries']) {
        echo json_encode(['ok' => false, 'error' => 'Max retries reached for this output']);
        exit;
    }

    // Reset output to pending and ensure the parent job is also pending/processing
    $pdo->prepare(
        'UPDATE edit_job_outputs
         SET status = "pending", error_message = NULL, ffmpeg_command = NULL,
             retry_count = retry_count + 1, updated_at = NOW()
         WHERE id = :id'
    )->execute(['id' => $outputId]);

    $pdo->prepare(
        'UPDATE edit_jobs SET status = "pending", updated_at = NOW()
         WHERE id = :jid AND status IN ("failed","completed")'
    )->execute(['jid' => $output['job_id']]);

    echo json_encode(['ok' => true, 'message' => 'Output queued for retry.']);
    exit;
}

// ── POST: create ──────────────────────────────────────────────────────────────
if ($action !== 'create') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
    exit;
}

// Validate required fields
$label        = trim((string) ($_POST['label']        ?? 'Untitled Edit Job'));
$sourcePath   = trim((string) ($_POST['source_path']  ?? ''));
$captionMode  = trim((string) ($_POST['caption_mode'] ?? 'none'));
$clipId       = (int) ($_POST['source_clip_id'] ?? 0) ?: null;
$platforms    = $_POST['platforms'] ?? [];
$maxRetries   = max(0, min(10, (int) ($_POST['max_retries'] ?? 3)));

if ($sourcePath === '') {
    echo json_encode(['ok' => false, 'error' => 'source_path is required']);
    exit;
}

if (!vp_is_safe_path($sourcePath)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid source_path']);
    exit;
}

if (!in_array($captionMode, ['none', 'embedded', 'sidecar'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid caption_mode']);
    exit;
}

if (!is_array($platforms)) {
    $platforms = [];
}

// Normalize platforms
$allowedPlatforms = ['YouTube', 'YouTube Shorts', 'TikTok', 'Instagram Reels', 'Facebook Reels', 'X', 'generic'];
$platforms = array_values(array_filter($platforms, fn($p) => in_array(trim((string) $p), $allowedPlatforms, true)));

if (count($platforms) === 0) {
    $platforms = ['generic'];
}

// Validate source_clip_id if provided
if ($clipId !== null) {
    $clipCheck = $pdo->prepare('SELECT id FROM video_clips WHERE id = :id');
    $clipCheck->execute(['id' => $clipId]);
    if (!$clipCheck->fetch()) {
        $clipId = null;
    }
}

// Validate overlay paths in layers
$overlayPath = trim((string) ($_POST['overlay_path'] ?? ''));
if ($overlayPath !== '' && !vp_is_safe_path($overlayPath)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid overlay_path']);
    exit;
}

$imageLayers = [];
$rawLayers = $_POST['image_layers'] ?? [];
if (is_array($rawLayers)) {
    foreach ($rawLayers as $rawLayer) {
        if (!is_array($rawLayer)) {
            continue;
        }
        $layerPath = trim((string) ($rawLayer['path'] ?? ''));
        if ($layerPath === '' || !vp_is_safe_path($layerPath)) {
            continue;
        }
        $position = trim((string) ($rawLayer['position'] ?? 'bottom-right'));
        if (!in_array($position, ['top-left','top-right','bottom-left','bottom-right','center','full'], true)) {
            $position = 'bottom-right';
        }
        $imageLayers[] = [
            'path'      => $layerPath,
            'position'  => $position,
            'scale'     => max(1, min(100, (int) ($rawLayer['scale']   ?? 30))),
            'opacity'   => max(0.0, min(1.0, (float) ($rawLayer['opacity'] ?? 1.0))),
            'start_sec' => isset($rawLayer['start_sec']) ? (float) $rawLayer['start_sec'] : null,
            'end_sec'   => isset($rawLayer['end_sec'])   ? (float) $rawLayer['end_sec']   : null,
        ];
    }
}

// Create edit_job row
$jobStmt = $pdo->prepare(
    'INSERT INTO edit_jobs
     (label, source_clip_id, source_path, caption_mode, platforms_json, status,
      max_retries, created_by, created_at, updated_at)
     VALUES (:label, :clip, :src, :cm, :plat, "pending", :maxr, :user, NOW(), NOW())'
);
$jobStmt->execute([
    'label' => $label,
    'clip'  => $clipId,
    'src'   => ltrim($sourcePath, '/uploads/'),
    'cm'    => $captionMode,
    'plat'  => json_encode($platforms),
    'maxr'  => $maxRetries,
    'user'  => (int) ($_SESSION['admin_user_id'] ?? 0),
]);
$jobId = (int) $pdo->lastInsertId();

// Create one edit_job_outputs row per platform
$outStmt = $pdo->prepare(
    'INSERT INTO edit_job_outputs
     (job_id, platform, caption_mode, overlay_path, image_layers_json, status, created_at, updated_at)
     VALUES (:jid, :platform, :cm, :overlay, :layers, "pending", NOW(), NOW())'
);

foreach ($platforms as $platform) {
    $outStmt->execute([
        'jid'     => $jobId,
        'platform'=> $platform,
        'cm'      => $captionMode,
        'overlay' => $overlayPath ?: null,
        'layers'  => !empty($imageLayers) ? json_encode($imageLayers) : null,
    ]);
}

$outputCount = count($platforms);

// Process synchronously if job has very few outputs
$SYNC_LIMIT = 3;

if ($outputCount <= $SYNC_LIMIT) {
    $jobRow = $pdo->prepare('SELECT * FROM edit_jobs WHERE id = :id');
    $jobRow->execute(['id' => $jobId]);
    $job = $jobRow->fetch(PDO::FETCH_ASSOC);

    $pdo->prepare(
        'UPDATE edit_jobs SET status = "processing", updated_at = NOW() WHERE id = :id'
    )->execute(['id' => $jobId]);

    $pendingOutputs = $pdo->prepare(
        'SELECT * FROM edit_job_outputs WHERE job_id = :jid AND status = "pending" ORDER BY id'
    );
    $pendingOutputs->execute(['jid' => $jobId]);

    foreach ($pendingOutputs->fetchAll() as $output) {
        process_edit_job_output($pdo, $output, $job);
    }

    // Determine final job status
    $failCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM edit_job_outputs WHERE job_id = :jid AND status = "failed"'
    );
    $failCheck->execute(['jid' => $jobId]);
    $doneCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM edit_job_outputs WHERE job_id = :jid AND status = "done"'
    );
    $doneCheck->execute(['jid' => $jobId]);
    $finalStatus = ((int) $failCheck->fetchColumn() === 0 && (int) $doneCheck->fetchColumn() > 0)
        ? 'completed' : 'failed';

    $pdo->prepare(
        'UPDATE edit_jobs SET status = :status, updated_at = NOW() WHERE id = :id'
    )->execute(['status' => $finalStatus, 'id' => $jobId]);
}

echo json_encode([
    'ok'           => true,
    'job_id'       => $jobId,
    'output_count' => $outputCount,
    'message'      => 'Edit job created.',
]);

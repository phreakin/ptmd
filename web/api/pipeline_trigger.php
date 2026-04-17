<?php
/**
 * PTMD API — Pipeline Trigger
 *
 * POST  — Start (and synchronously run) a pipeline job for a completed clip.
 * GET   — Poll status of an existing pipeline job.
 *
 * Security: requires active admin session.
 *
 * POST body (form-data or JSON):
 *   csrf_token           string  required
 *   clip_id              int     required — video_clips.id to process
 *   overlay_path         string  optional — override brand overlay path
 *   position             string  optional — overlay position
 *   opacity              float   optional
 *   scale                int     optional
 *   platforms[]          array   optional — platform slugs
 *   auto_queue           0|1     optional
 *   schedule_offset_hrs  int     optional
 *   caption_template     string  optional
 *
 * GET ?job_id=N           — returns JSON status summary
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/pipeline.php';

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

// ── GET: Status poll ──────────────────────────────────────────────────────────

if (!is_post()) {
    $jobId = isset($_GET['job_id']) ? (int) $_GET['job_id'] : 0;

    if ($jobId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing job_id']);
        exit;
    }

    $summary = get_pipeline_job_summary($jobId);
    if (!$summary) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Job not found']);
        exit;
    }

    echo json_encode(['ok' => true, 'job' => $summary]);
    exit;
}

// ── POST: Trigger pipeline ────────────────────────────────────────────────────

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$clipId = (int) ($_POST['clip_id'] ?? 0);
if ($clipId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing clip_id']);
    exit;
}

// Verify clip exists and is in a triggerable state
$stmt = $pdo->prepare('SELECT id, status FROM video_clips WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $clipId]);
$clip = $stmt->fetch();

if (!$clip) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Clip not found']);
    exit;
}

if (!in_array($clip['status'], ['raw', 'ready', 'complete'], true)) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'Clip status "' . $clip['status'] . '" cannot be sent to pipeline']);
    exit;
}

// Collect optional overrides
$options = [];

$map = [
    'overlay_path'        => 'string',
    'position'            => 'string',
    'opacity'             => 'float',
    'scale'               => 'int',
    'auto_queue'          => 'int',
    'schedule_offset_hrs' => 'int',
    'caption_template'    => 'string',
];

foreach ($map as $key => $type) {
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        continue;
    }
    $options[$key] = match($type) {
        'float' => (float) $_POST[$key],
        'int'   => (int)   $_POST[$key],
        default => trim((string) $_POST[$key]),
    };
}

if (!empty($_POST['platforms']) && is_array($_POST['platforms'])) {
    $allowed  = array_keys(get_platform_profiles());
    $options['platforms'] = array_values(
        array_filter($_POST['platforms'], fn($p) => in_array(trim((string)$p), $allowed, true))
    );
}

// Allow longer execution for FFmpeg processing
set_time_limit(300);

// Trigger (or return existing) job
$jobId = trigger_pipeline_for_clip($clipId, $options);

if ($jobId === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to create pipeline job']);
    exit;
}

// Process all stages synchronously
$result = process_pipeline_job($jobId);

$summary = get_pipeline_job_summary($jobId);

echo json_encode([
    'ok'      => $result['ok'],
    'job_id'  => $jobId,
    'message' => $result['ok'] ? ($result['message'] ?? 'Pipeline started.') : ($result['error'] ?? 'Pipeline failed.'),
    'job'     => $summary,
]);

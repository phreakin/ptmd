<?php
/**
 * PTMD API — Script Video
 *
 * GET  ?job_id=N               — Poll status of an existing script-video job.
 * POST _action=create          — Create a new script-video job.
 * POST _action=generate_scenes — AI-generate a scene plan without creating a job.
 * POST _action=cancel          — Cancel a pending or processing job.
 *
 * Security: requires admin session + CSRF for all POST requests.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/video_processor.php';
require_once __DIR__ . '/../inc/script_video.php';

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

// ── GET: Poll job status ──────────────────────────────────────────────────────
if (!is_post()) {
    $jobId = isset($_GET['job_id']) ? (int) $_GET['job_id'] : 0;

    if ($jobId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Missing job_id']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM script_video_jobs WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $jobId]);
    $job = $stmt->fetch();

    if (!$job) {
        echo json_encode(['ok' => false, 'error' => 'Job not found']);
        exit;
    }

    $response = [
        'ok'          => true,
        'job_id'      => (int) $job['id'],
        'status'      => $job['status'],
        'progress'    => (int) $job['progress'],
        'phase'       => $job['phase'] ?? '',
        'error'       => $job['error_message'] ?? null,
        'output_path' => $job['output_path']
                            ? upload_url((string) $job['output_path'])
                            : null,
        'output_duration' => $job['output_duration'] !== null
                            ? (float) $job['output_duration']
                            : null,
    ];

    echo json_encode($response);
    exit;
}

// ── POST: Require CSRF ────────────────────────────────────────────────────────
if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action = trim((string) ($_POST['_action'] ?? 'create'));
$userId = (int) ($_SESSION['admin_user_id'] ?? 0);

// ── POST: generate_scenes (AI preview before submitting the job) ──────────────
if ($action === 'generate_scenes') {
    $prompt = trim((string) ($_POST['ai_prompt'] ?? ''));
    $title  = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 255);

    if ($prompt === '') {
        echo json_encode(['ok' => false, 'error' => 'AI prompt is required.']);
        exit;
    }
    if (mb_strlen($prompt) > 2000) {
        echo json_encode(['ok' => false, 'error' => 'AI prompt must be 2000 characters or fewer.']);
        exit;
    }

    $result = sv_ai_generate_scenes($prompt, $title);

    if (!$result['ok']) {
        echo json_encode(['ok' => false, 'error' => $result['error']]);
        exit;
    }

    // Persist the AI call for auditability
    save_ai_generation(
        'script_scene_plan',
        $prompt,
        $result['raw'],
        $result['model'],
        $result['prompt_tokens'],
        $result['response_tokens']
    );

    echo json_encode([
        'ok'     => true,
        'scenes' => $result['scenes'],
    ]);
    exit;
}

// ── POST: cancel ─────────────────────────────────────────────────────────────
if ($action === 'cancel') {
    $jobId = (int) ($_POST['job_id'] ?? 0);
    if ($jobId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Missing job_id']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT status FROM script_video_jobs WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $jobId]);
    $job = $stmt->fetch();

    if (!$job) {
        echo json_encode(['ok' => false, 'error' => 'Job not found']);
        exit;
    }

    if (!in_array($job['status'], ['pending', 'processing'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Only pending or processing jobs can be canceled.']);
        exit;
    }

    $pdo->prepare(
        'UPDATE script_video_jobs SET status="canceled", updated_at=NOW() WHERE id=:id'
    )->execute(['id' => $jobId]);

    echo json_encode(['ok' => true, 'job_id' => $jobId, 'status' => 'canceled']);
    exit;
}

// ── POST: create ─────────────────────────────────────────────────────────────
if ($action !== 'create') {
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit;
}

$inputMode = trim((string) ($_POST['input_mode'] ?? 'manual'));
$title     = trim((string) ($_POST['title']      ?? ''));
$episodeId = (int) ($_POST['episode_id'] ?? 0);

$data = [
    'input_mode'   => $inputMode,
    'title'        => $title,
    'script_source'=> trim((string) ($_POST['script_source'] ?? '')),
    'ai_prompt'    => trim((string) ($_POST['ai_prompt']     ?? '')),
    'episode_id'   => $episodeId > 0 ? $episodeId : null,
    'scenes'       => [],
];

// Validate
$errors = sv_validate_job($data);
if ($errors) {
    echo json_encode(['ok' => false, 'error' => implode(' ', $errors)]);
    exit;
}

// Generate or parse scenes
if ($inputMode === 'ai') {
    $aiResult = sv_ai_generate_scenes($data['ai_prompt'], $data['title']);
    if (!$aiResult['ok']) {
        echo json_encode(['ok' => false, 'error' => $aiResult['error']]);
        exit;
    }

    $data['scenes'] = $aiResult['scenes'];

    // Persist AI call for auditability
    save_ai_generation(
        'script_scene_plan',
        $data['ai_prompt'],
        $aiResult['raw'],
        $aiResult['model'],
        $aiResult['prompt_tokens'],
        $aiResult['response_tokens']
    );
} else {
    // Manual mode — accept either pre-parsed scenes or parse from script text
    $rawScenes = $_POST['scenes'] ?? null;
    if (is_array($rawScenes) && count($rawScenes) > 0) {
        // Caller submitted explicit scene rows
        $order = 1;
        foreach ($rawScenes as $s) {
            if (!is_array($s)) {
                continue;
            }
            $narration = trim((string) ($s['narration_text'] ?? ''));
            if ($narration === '') {
                continue;
            }
            $data['scenes'][] = [
                'scene_order'    => $order,
                'narration_text' => $narration,
                'visual_prompt'  => isset($s['visual_prompt']) ? trim((string) $s['visual_prompt']) : null,
                'asset_path'     => null,
                'duration_sec'   => max(
                    SV_MIN_SCENE_DUR,
                    min(SV_MAX_SCENE_DUR, (float) ($s['duration_sec'] ?? SV_DEFAULT_SCENE_DUR))
                ),
                'overlay_text'   => isset($s['overlay_text']) ? mb_substr(trim((string) $s['overlay_text']), 0, 500) : null,
            ];
            $order++;
            if ($order > SV_MAX_SCENES + 1) {
                break;
            }
        }
    } else {
        // Auto-parse from script_source
        $data['scenes'] = sv_parse_scenes($data['script_source']);
    }

    if (empty($data['scenes'])) {
        echo json_encode(['ok' => false, 'error' => 'No scenes could be parsed from the script. Separate scenes with blank lines.']);
        exit;
    }

    if (count($data['scenes']) > SV_MAX_SCENES) {
        echo json_encode(['ok' => false, 'error' => 'Script has too many scenes (max ' . SV_MAX_SCENES . ').']);
        exit;
    }
}

// Persist job
$jobId = sv_create_job($pdo, $data, $userId);
if ($jobId === 0) {
    echo json_encode(['ok' => false, 'error' => 'Failed to create job. Please try again.']);
    exit;
}

// Process small jobs synchronously (≤ 4 scenes)
$SYNC_LIMIT = 4;
if (count($data['scenes']) <= $SYNC_LIMIT) {
    sv_process_job($pdo, $jobId);
} else {
    // Mark as processing; background or next poll trigger will continue
    $pdo->prepare(
        'UPDATE script_video_jobs SET status="processing", updated_at=NOW() WHERE id=:id'
    )->execute(['id' => $jobId]);
}

// Return current status
$stmt = $pdo->prepare('SELECT status, progress, phase, error_message, output_path FROM script_video_jobs WHERE id=:id');
$stmt->execute(['id' => $jobId]);
$fresh = $stmt->fetch();

echo json_encode([
    'ok'          => true,
    'job_id'      => $jobId,
    'scene_count' => count($data['scenes']),
    'status'      => $fresh['status']   ?? 'pending',
    'progress'    => (int) ($fresh['progress'] ?? 0),
    'phase'       => $fresh['phase']    ?? '',
    'error'       => $fresh['error_message'] ?? null,
    'output_path' => ($fresh['output_path'] ?? null)
                        ? upload_url((string) $fresh['output_path'])
                        : null,
    'message'     => 'Script video job created.',
]);

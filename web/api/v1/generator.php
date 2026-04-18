<?php
/**
 * PTMD API v1 — Content Generator
 *
 * POST {"action":"titles"}          Generate title variants for a case.
 * POST {"action":"captions"}        Generate social captions.
 * POST {"action":"thumbnail_text"}  Generate thumbnail text overlays.
 * POST {"action":"cta"}             Generate calls-to-action.
 * POST {"action":"script_outline"}  Generate a script outline.
 * POST {"action":"hooks"}           Generate hook variants.
 *
 * Requires admin session. CSRF required on all POST.
 */

require_once __DIR__ . '/../../inc/bootstrap.php';
require_once __DIR__ . '/../../inc/services/ContentGenerationService.php';
require_once __DIR__ . '/../../inc/services/HookService.php';
require_once __DIR__ . '/../../inc/services/EventTrackingService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'data' => null, 'error' => 'Unauthorized']);
    exit;
}

$pdo    = get_db();
$admin  = current_admin();
$userId = (int) ($admin['id'] ?? 0);

if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'data' => null, 'error' => 'Database unavailable']);
    exit;
}

$method  = $_SERVER['REQUEST_METHOD'];
$traceId = ptmd_generate_trace_id();

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'data' => null, 'error' => 'Method not allowed']);
    exit;
}

$csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!verify_csrf($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'data' => null, 'error' => 'Invalid CSRF token', 'trace_id' => $traceId]);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = (string) ($body['action'] ?? $_POST['action'] ?? '');

switch ($action) {
    case 'titles':
        $caseId = (int) ($body['case_id'] ?? 0);
        if ($caseId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'case_id required', 'trace_id' => $traceId]);
            exit;
        }
        $result = ptmd_generate_titles($caseId, $body['context'] ?? []);
        if (!$result['ok']) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'data' => null, 'error' => $result['error'], 'trace_id' => $traceId]);
            exit;
        }
        echo json_encode([
            'ok'            => true,
            'data'          => ['titles' => $result['titles']],
            'generation_id' => $result['generation_id'],
            'error'         => null,
            'trace_id'      => $traceId,
        ]);
        exit;

    case 'captions':
        $caseId   = (int)    ($body['case_id']  ?? 0);
        $platform = (string) ($body['platform'] ?? 'all');
        if ($caseId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'case_id required', 'trace_id' => $traceId]);
            exit;
        }
        $result = ptmd_generate_captions($caseId, $platform);
        if (!$result['ok']) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'data' => null, 'error' => $result['error'], 'trace_id' => $traceId]);
            exit;
        }
        echo json_encode([
            'ok'            => true,
            'data'          => ['captions' => $result['captions']],
            'generation_id' => $result['generation_id'],
            'error'         => null,
            'trace_id'      => $traceId,
        ]);
        exit;

    case 'thumbnail_text':
        $caseId = (int) ($body['case_id'] ?? 0);
        if ($caseId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'case_id required', 'trace_id' => $traceId]);
            exit;
        }
        $result = ptmd_generate_thumbnail_text($caseId);
        if (!$result['ok']) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'data' => null, 'error' => $result['error'], 'trace_id' => $traceId]);
            exit;
        }
        echo json_encode([
            'ok'            => true,
            'data'          => ['thumbnail_text' => $result['thumbnail_text'] ?? $result['texts'] ?? []],
            'generation_id' => $result['generation_id'],
            'error'         => null,
            'trace_id'      => $traceId,
        ]);
        exit;

    case 'cta':
        $contentType = (string) ($body['content_type'] ?? '');
        $platform    = (string) ($body['platform']     ?? 'all');
        if ($contentType === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'content_type required', 'trace_id' => $traceId]);
            exit;
        }
        $result = ptmd_generate_cta($contentType, $platform, $body['context'] ?? []);
        if (!$result['ok']) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'data' => null, 'error' => $result['error'], 'trace_id' => $traceId]);
            exit;
        }
        echo json_encode([
            'ok'            => true,
            'data'          => ['ctas' => $result['ctas'] ?? $result['cta'] ?? []],
            'generation_id' => $result['generation_id'],
            'error'         => null,
            'trace_id'      => $traceId,
        ]);
        exit;

    case 'script_outline':
        $caseId      = (int) ($body['case_id']     ?? 0);
        $blueprintId = (int) ($body['blueprint_id'] ?? 0) ?: null;
        if ($caseId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'case_id required', 'trace_id' => $traceId]);
            exit;
        }
        $result = ptmd_generate_script_outline($caseId, $blueprintId);
        if (!$result['ok']) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'data' => null, 'error' => $result['error'], 'trace_id' => $traceId]);
            exit;
        }
        ptmd_emit_event('content.script_outline.generated', 'content_generation', 'case', $caseId,
            ['generation_id' => $result['generation_id'] ?? null],
            $userId, $traceId, null, null, null, null, 'generated', 'ai');
        echo json_encode([
            'ok'            => true,
            'data'          => $result,
            'generation_id' => $result['generation_id'] ?? null,
            'error'         => null,
            'trace_id'      => $traceId,
        ]);
        exit;

    case 'hooks':
        $caseId   = (int)    ($body['case_id']   ?? 0);
        $platform = (string) ($body['platform']  ?? 'all');
        $hookType = (string) ($body['hook_type'] ?? '') ?: null;
        if ($caseId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'case_id required', 'trace_id' => $traceId]);
            exit;
        }
        $result = ptmd_hook_generate($caseId, $platform, $hookType, [], $userId);
        if (!$result['ok']) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'data' => null, 'error' => $result['error'], 'trace_id' => $traceId]);
            exit;
        }
        echo json_encode([
            'ok'            => true,
            'data'          => ['hooks' => $result['hooks']],
            'generation_id' => $result['generation_id'] ?? null,
            'error'         => null,
            'trace_id'      => $traceId,
        ]);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'data' => null, 'error' => 'Unknown action', 'trace_id' => $traceId]);
        exit;
}

<?php
/**
 * PTMD API v1 — A/B Experiments
 *
 * GET  ?action=list              List experiment runs by status.
 * GET  ?action=get&id=N          Get experiment with variants and event counts.
 * POST {"action":"create"}       Create a new experiment.
 * POST {"action":"add_variant"}  Add a variant to an experiment.
 * POST {"action":"start"}        Start an experiment.
 * POST {"action":"pause"}        Pause a running experiment.
 * POST {"action":"complete"}     Mark experiment complete with winner.
 * POST {"action":"record_event"} Record an impression/conversion event.
 *
 * Requires admin session.
 */

require_once __DIR__ . '/../../inc/bootstrap.php';
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

// ── GET ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? '');

    if ($action === 'list') {
        $status  = (string) ($_GET['status']   ?? '');
        $page    = max(1, (int) ($_GET['page']     ?? 1));
        $perPage = min((int) ($_GET['per_page'] ?? 50), 100);
        $offset  = ($page - 1) * $perPage;

        $sql    = 'SELECT * FROM experiment_runs';
        $params = [];
        if ($status !== '') {
            $sql .= ' WHERE status = :status';
            $params[':status'] = $status;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(), 'error' => null, 'trace_id' => $traceId]);
        exit;
    }

    if ($action === 'get') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'id required', 'trace_id' => $traceId]);
            exit;
        }

        $expStmt = $pdo->prepare('SELECT * FROM experiment_runs WHERE id = :id LIMIT 1');
        $expStmt->execute([':id' => $id]);
        $experiment = $expStmt->fetch();
        if (!$experiment) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'data' => null, 'error' => "Experiment #{$id} not found", 'trace_id' => $traceId]);
            exit;
        }

        // Variants
        $varStmt = $pdo->prepare('SELECT * FROM experiment_variants WHERE experiment_id = :id ORDER BY id ASC');
        $varStmt->execute([':id' => $id]);
        $variants = $varStmt->fetchAll();

        // Event counts per variant
        $evtStmt = $pdo->prepare(
            'SELECT variant_id, event_type, COUNT(*) AS event_count, SUM(value) AS total_value
             FROM experiment_events WHERE experiment_id = :id
             GROUP BY variant_id, event_type'
        );
        $evtStmt->execute([':id' => $id]);
        $events = $evtStmt->fetchAll();

        echo json_encode([
            'ok'   => true,
            'data' => [
                'experiment' => $experiment,
                'variants'   => $variants,
                'events'     => $events,
            ],
            'error'    => null,
            'trace_id' => $traceId,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'data' => null, 'error' => 'Unknown action', 'trace_id' => $traceId]);
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!verify_csrf($csrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'data' => null, 'error' => 'Invalid CSRF token', 'trace_id' => $traceId]);
        exit;
    }

    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = (string) ($body['action'] ?? $_POST['action'] ?? '');

    switch ($action) {
        case 'create':
            $name            = trim((string) ($body['name']            ?? ''));
            $experimentType  = (string) ($body['experiment_type']  ?? 'hook');
            $hypothesis      = (string) ($body['hypothesis']        ?? '');
            $minSampleSize   = (int)    ($body['min_sample_size']   ?? 100);

            if ($name === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'name required', 'trace_id' => $traceId]);
                exit;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO experiment_runs
                     (name, experiment_type, hypothesis, min_sample_size, status, created_by, created_at, updated_at)
                 VALUES
                     (:name, :type, :hypothesis, :min_sample, :status, :uid, NOW(), NOW())'
            );
            $stmt->execute([
                ':name'       => $name,
                ':type'       => $experimentType,
                ':hypothesis' => $hypothesis,
                ':min_sample' => $minSampleSize,
                ':status'     => 'draft',
                ':uid'        => $userId,
            ]);
            $experimentId = (int) $pdo->lastInsertId();

            ptmd_emit_event('experiment.created', 'analytics', 'experiment_run', $experimentId,
                ['name' => $name, 'type' => $experimentType],
                $userId, $traceId, null, null, null, null, 'ok', 'human');

            echo json_encode([
                'ok'   => true,
                'data' => ['experiment_id' => $experimentId, 'status' => 'draft'],
                'error'    => null,
                'trace_id' => $traceId,
            ]);
            exit;

        case 'add_variant':
            $experimentId     = (int)    ($body['experiment_id']    ?? 0);
            $variantKey       = (string) ($body['variant_key']       ?? '');
            $contentText      = (string) ($body['content_text']      ?? '');
            $allocationWeight = (int)    ($body['allocation_weight'] ?? 50);
            $isControl        = (int)    ($body['is_control']        ?? 0);

            if ($experimentId <= 0 || $variantKey === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'experiment_id and variant_key required', 'trace_id' => $traceId]);
                exit;
            }

            // Verify experiment exists
            $chk = $pdo->prepare('SELECT id FROM experiment_runs WHERE id = :id LIMIT 1');
            $chk->execute([':id' => $experimentId]);
            if (!$chk->fetch()) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'data' => null, 'error' => "Experiment #{$experimentId} not found", 'trace_id' => $traceId]);
                exit;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO experiment_variants
                     (experiment_id, variant_key, content_text, allocation_weight, is_control, created_at)
                 VALUES
                     (:exp_id, :key, :content, :weight, :is_control, NOW())'
            );
            $stmt->execute([
                ':exp_id'     => $experimentId,
                ':key'        => $variantKey,
                ':content'    => $contentText,
                ':weight'     => $allocationWeight,
                ':is_control' => $isControl,
            ]);
            $variantId = (int) $pdo->lastInsertId();

            echo json_encode([
                'ok'   => true,
                'data' => ['variant_id' => $variantId, 'experiment_id' => $experimentId],
                'error'    => null,
                'trace_id' => $traceId,
            ]);
            exit;

        case 'start':
            $experimentId = (int) ($body['experiment_id'] ?? 0);
            if ($experimentId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'experiment_id required', 'trace_id' => $traceId]);
                exit;
            }
            $pdo->prepare(
                "UPDATE experiment_runs SET status = 'running', started_at = NOW(), updated_at = NOW() WHERE id = :id"
            )->execute([':id' => $experimentId]);

            ptmd_emit_event('experiment.started', 'analytics', 'experiment_run', $experimentId,
                [], $userId, $traceId, null, 'draft', 'running', null, 'ok', 'human');

            echo json_encode(['ok' => true, 'data' => ['experiment_id' => $experimentId, 'status' => 'running'], 'error' => null, 'trace_id' => $traceId]);
            exit;

        case 'pause':
            $experimentId = (int) ($body['experiment_id'] ?? 0);
            if ($experimentId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'experiment_id required', 'trace_id' => $traceId]);
                exit;
            }
            $pdo->prepare(
                "UPDATE experiment_runs SET status = 'paused', updated_at = NOW() WHERE id = :id"
            )->execute([':id' => $experimentId]);

            ptmd_emit_event('experiment.paused', 'analytics', 'experiment_run', $experimentId,
                [], $userId, $traceId, null, 'running', 'paused', null, 'ok', 'human');

            echo json_encode(['ok' => true, 'data' => ['experiment_id' => $experimentId, 'status' => 'paused'], 'error' => null, 'trace_id' => $traceId]);
            exit;

        case 'complete':
            $experimentId    = (int)    ($body['experiment_id']    ?? 0);
            $winnerVariantId = (int)    ($body['winner_variant_id'] ?? 0) ?: null;
            $conclusionText  = (string) ($body['conclusion_text']   ?? '');

            if ($experimentId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'experiment_id required', 'trace_id' => $traceId]);
                exit;
            }

            $pdo->prepare(
                "UPDATE experiment_runs
                 SET status = 'completed', completed_at = NOW(), updated_at = NOW(),
                     winner_variant_id = :winner, conclusion_text = :conclusion
                 WHERE id = :id"
            )->execute([
                ':winner'     => $winnerVariantId,
                ':conclusion' => $conclusionText,
                ':id'         => $experimentId,
            ]);

            ptmd_emit_event('experiment.completed', 'analytics', 'experiment_run', $experimentId,
                ['winner_variant_id' => $winnerVariantId],
                $userId, $traceId, null, 'running', 'completed', null, 'ok', 'human');

            echo json_encode([
                'ok'   => true,
                'data' => ['experiment_id' => $experimentId, 'status' => 'completed', 'winner_variant_id' => $winnerVariantId],
                'error'    => null,
                'trace_id' => $traceId,
            ]);
            exit;

        case 'record_event':
            $experimentId = (int)    ($body['experiment_id'] ?? 0);
            $variantId    = (int)    ($body['variant_id']    ?? 0);
            $eventType    = (string) ($body['event_type']    ?? '');
            $value        = (float)  ($body['value']         ?? 1);

            if ($experimentId <= 0 || $variantId <= 0 || $eventType === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'experiment_id, variant_id, and event_type required', 'trace_id' => $traceId]);
                exit;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO experiment_events
                     (experiment_id, variant_id, event_type, value, recorded_at)
                 VALUES
                     (:exp_id, :var_id, :event_type, :value, NOW())'
            );
            $stmt->execute([
                ':exp_id'     => $experimentId,
                ':var_id'     => $variantId,
                ':event_type' => $eventType,
                ':value'      => $value,
            ]);
            $eventId = (int) $pdo->lastInsertId();

            ptmd_emit_event('experiment.event_recorded', 'analytics', 'experiment_run', $experimentId,
                ['variant_id' => $variantId, 'event_type' => $eventType, 'value' => $value],
                $userId, $traceId, null, null, null, null, 'ok', 'api');

            echo json_encode([
                'ok'       => true,
                'data'     => ['event_id' => $eventId],
                'error'    => null,
                'trace_id' => $traceId,
            ]);
            exit;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'Unknown action', 'trace_id' => $traceId]);
            exit;
    }
}

http_response_code(405);
echo json_encode(['ok' => false, 'data' => null, 'error' => 'Method not allowed']);
exit;

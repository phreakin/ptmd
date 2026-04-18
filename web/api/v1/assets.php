<?php
/**
 * PTMD API v1 — Asset Graph & Lineage
 *
 * GET  ?action=dependencies   Upstream relations for an asset.
 * GET  ?action=dependents     Downstream relations for an asset.
 * GET  ?action=lineage        Full recursive lineage tree.
 * GET  ?action=orphans        Assets with no relations.
 * GET  ?action=full           Full asset detail record.
 * POST {"action":"link"}      Create an asset relation.
 * POST {"action":"check_duplicate"} Check for file path duplicate.
 *
 * Requires admin session.
 */

require_once __DIR__ . '/../../inc/bootstrap.php';
require_once __DIR__ . '/../../inc/services/AssetGraphService.php';
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
    $table  = (string) ($_GET['table']  ?? 'assets');
    $id     = (int)    ($_GET['id']     ?? 0);

    switch ($action) {
        case 'dependencies':
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'id required', 'trace_id' => $traceId]);
                exit;
            }
            $data = ptmd_asset_get_dependencies($table, $id);
            echo json_encode(['ok' => true, 'data' => $data, 'error' => null, 'trace_id' => $traceId]);
            exit;

        case 'dependents':
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'id required', 'trace_id' => $traceId]);
                exit;
            }
            $data = ptmd_asset_get_dependents($table, $id);
            echo json_encode(['ok' => true, 'data' => $data, 'error' => null, 'trace_id' => $traceId]);
            exit;

        case 'lineage':
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'id required', 'trace_id' => $traceId]);
                exit;
            }
            $tree = ptmd_asset_lineage_tree($table, $id);
            echo json_encode(['ok' => true, 'data' => $tree, 'error' => null, 'trace_id' => $traceId]);
            exit;

        case 'orphans':
            $limit = min((int) ($_GET['limit'] ?? 50), 100);
            $data  = ptmd_asset_find_orphans($limit);
            echo json_encode(['ok' => true, 'data' => $data, 'error' => null, 'trace_id' => $traceId]);
            exit;

        case 'full':
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'data' => null, 'error' => 'id required', 'trace_id' => $traceId]);
                exit;
            }
            $asset = ptmd_asset_get_full($id);
            if (!$asset) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'data' => null, 'error' => "Asset #{$id} not found", 'trace_id' => $traceId]);
                exit;
            }
            echo json_encode(['ok' => true, 'data' => $asset, 'error' => null, 'trace_id' => $traceId]);
            exit;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'Unknown action', 'trace_id' => $traceId]);
            exit;
    }
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

    if ($action === 'link') {
        $sourceTable  = (string) ($body['source_table']  ?? '');
        $sourceId     = (int)    ($body['source_id']     ?? 0);
        $targetTable  = (string) ($body['target_table']  ?? '');
        $targetId     = (int)    ($body['target_id']     ?? 0);
        $relationType = (string) ($body['relation_type'] ?? 'used_in');
        $meta         = (array)  ($body['meta']          ?? []);

        if ($sourceTable === '' || $sourceId <= 0 || $targetTable === '' || $targetId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'source_table, source_id, target_table, target_id required', 'trace_id' => $traceId]);
            exit;
        }

        $ok = ptmd_asset_link($sourceTable, $sourceId, $targetTable, $targetId, $relationType, $meta);
        if (!$ok) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'Failed to create asset relation', 'trace_id' => $traceId]);
            exit;
        }

        ptmd_emit_event('asset.linked', 'assets', $sourceTable, $sourceId,
            ['target_table' => $targetTable, 'target_id' => $targetId, 'relation_type' => $relationType],
            $userId, $traceId, null, null, null, null, 'ok', 'human');

        echo json_encode(['ok' => true, 'data' => ['linked' => true], 'error' => null, 'trace_id' => $traceId]);
        exit;
    }

    if ($action === 'check_duplicate') {
        $filePath = (string) ($body['file_path'] ?? '');
        if ($filePath === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'file_path required', 'trace_id' => $traceId]);
            exit;
        }
        $duplicate = ptmd_asset_check_duplicate($filePath);
        echo json_encode([
            'ok'        => true,
            'data'      => ['duplicate' => $duplicate !== null, 'existing' => $duplicate],
            'error'     => null,
            'trace_id'  => $traceId,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'data' => null, 'error' => 'Unknown action', 'trace_id' => $traceId]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'data' => null, 'error' => 'Method not allowed']);
exit;

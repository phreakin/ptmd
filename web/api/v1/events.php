<?php
/**
 * PTMD API v1 — Event Log
 *
 * GET ?action=recent    Recent events with optional module/days filters.
 * GET ?action=by_object Events for a specific object.
 * GET ?action=summary   Event counts grouped by module and event_name.
 * GET ?action=errors    Events with error/failed status.
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

$pdo = get_db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'data' => null, 'error' => 'Database unavailable']);
    exit;
}

$traceId = ptmd_generate_trace_id();
$action  = (string) ($_GET['action'] ?? '');
$days    = max(1, (int) ($_GET['days'] ?? 7));
$limit   = min((int) ($_GET['limit'] ?? 50), 100);
$page    = max(1, (int) ($_GET['page'] ?? 1));
$offset  = ($page - 1) * $limit;

switch ($action) {
    // ── Recent events ─────────────────────────────────────────────────────────
    case 'recent':
        $module = (string) ($_GET['module'] ?? '');
        $sql    = 'SELECT * FROM ptmd_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)';
        $params = [':days' => $days];
        if ($module !== '') {
            $sql .= ' AND module = :module';
            $params[':module'] = $module;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  $limit,  \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(), 'error' => null, 'trace_id' => $traceId]);
        exit;

    // ── Events by object ─────────────────────────────────────────────────────
    case 'by_object':
        $objectType = (string) ($_GET['object_type'] ?? '');
        $objectId   = (int)    ($_GET['object_id']   ?? 0);
        if ($objectType === '' || $objectId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'data' => null, 'error' => 'object_type and object_id required', 'trace_id' => $traceId]);
            exit;
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM ptmd_events
              WHERE object_type = :otype AND object_id = :oid
              ORDER BY created_at DESC
              LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':otype',  $objectType);
        $stmt->bindValue(':oid',    $objectId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $limit,    \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,   \PDO::PARAM_INT);
        $stmt->execute();
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(), 'error' => null, 'trace_id' => $traceId]);
        exit;

    // ── Summary ───────────────────────────────────────────────────────────────
    case 'summary':
        $stmt = $pdo->prepare(
            'SELECT module, event_name, COUNT(*) AS event_count,
                    MAX(created_at) AS last_seen
             FROM ptmd_events
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
             GROUP BY module, event_name
             ORDER BY event_count DESC'
        );
        $stmt->execute([':days' => $days]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(), 'error' => null, 'trace_id' => $traceId]);
        exit;

    // ── Errors ────────────────────────────────────────────────────────────────
    case 'errors':
        $errDays = max(1, (int) ($_GET['days'] ?? 1));
        $stmt    = $pdo->prepare(
            "SELECT * FROM ptmd_events
              WHERE status IN ('error','failed')
                AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
              ORDER BY created_at DESC
              LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':days',   $errDays, \PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $limit,   \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(), 'error' => null, 'trace_id' => $traceId]);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'data' => null, 'error' => 'Unknown action', 'trace_id' => $traceId]);
        exit;
}

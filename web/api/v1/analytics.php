<?php
/**
 * PTMD API v1 — Analytics
 *
 * GET ?action=kpis                  Aggregate KPI dashboard metrics.
 * GET ?action=hook_performance      Hook performance averages.
 * GET ?action=optimizer_outcomes    Optimizer acceptance summary.
 * GET ?action=posting_health        Queue success/failure rates.
 * GET ?action=workflow_bottlenecks  Lifecycle stages with stale entities.
 * GET ?action=ai_costs              AI cost breakdown by feature/model.
 * GET ?action=trend_effectiveness   Trend clusters → case performance.
 *
 * Requires admin session.
 */

require_once __DIR__ . '/../../inc/bootstrap.php';
require_once __DIR__ . '/../../inc/services/LifecycleService.php';
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
$days    = max(1, (int) ($_GET['days'] ?? 30));

switch ($action) {
    // ── KPIs ─────────────────────────────────────────────────────────────────
    case 'kpis':
        // published_count
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM cases WHERE status = 'published' AND updated_at >= DATE_SUB(NOW(), INTERVAL :days DAY)"
        );
        $st->execute([':days' => $days]);
        $publishedCount = (int) $st->fetchColumn();

        // queued_count
        $st = $pdo->query("SELECT COUNT(*) FROM social_post_queue WHERE status = 'queued'");
        $queuedCount = (int) $st->fetchColumn();

        // failed_posts_24h
        $st = $pdo->query("SELECT COUNT(*) FROM social_post_queue WHERE status = 'failed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $failedPosts24h = (int) $st->fetchColumn();

        // hook_acceptance_rate
        $st  = $pdo->query("SELECT COUNT(*) FROM hooks WHERE status = 'approved'");
        $approved = (int) $st->fetchColumn();
        $st  = $pdo->query("SELECT COUNT(*) FROM hooks WHERE status IN ('approved','rejected')");
        $totalReviewed = (int) $st->fetchColumn();
        $hookAcceptanceRate = $totalReviewed > 0 ? round($approved / $totalReviewed * 100, 1) : 0;

        // ai_recommendation_acceptance
        $st = $pdo->query("SELECT COUNT(*) FROM optimizer_variants WHERE accepted = 1");
        $acceptedVariants = (int) $st->fetchColumn();
        $st = $pdo->query("SELECT COUNT(*) FROM optimizer_variants");
        $totalVariants = (int) $st->fetchColumn();
        $aiRecommendationAcceptance = $totalVariants > 0 ? round($acceptedVariants / $totalVariants * 100, 1) : 0;

        // pending_approvals
        $st = $pdo->query("SELECT COUNT(*) FROM editorial_approvals WHERE status = 'pending'");
        $pendingApprovals = (int) $st->fetchColumn();

        // active_trends
        $st = $pdo->query("SELECT COUNT(*) FROM trend_clusters WHERE status = 'active'");
        $activeTrends = (int) $st->fetchColumn();

        // total_hooks
        $st = $pdo->query("SELECT COUNT(*) FROM hooks");
        $totalHooks = (int) $st->fetchColumn();

        // ai_cost_30d
        $st = $pdo->query("SELECT COALESCE(SUM(estimated_cost_usd), 0) FROM ai_usage_costs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $aiCost30d = (float) $st->fetchColumn();

        // stale_workflows: count entities not moved in > 7 days
        $st = $pdo->query(
            "SELECT COUNT(*) FROM content_state_transitions t1
              WHERE t1.id = (
                SELECT id FROM content_state_transitions t2
                  WHERE t2.entity_type = t1.entity_type AND t2.entity_id = t1.entity_id
                  ORDER BY transitioned_at DESC LIMIT 1
              ) AND t1.transitioned_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
                AND t1.to_state NOT IN ('published','archived')"
        );
        $staleWorkflows = (int) $st->fetchColumn();

        echo json_encode([
            'ok'    => true,
            'data'  => [
                'published_count'               => $publishedCount,
                'queued_count'                  => $queuedCount,
                'failed_posts_24h'              => $failedPosts24h,
                'hook_acceptance_rate'          => $hookAcceptanceRate,
                'ai_recommendation_acceptance'  => $aiRecommendationAcceptance,
                'pending_approvals'             => $pendingApprovals,
                'active_trends'                 => $activeTrends,
                'total_hooks'                   => $totalHooks,
                'ai_cost_30d'                   => $aiCost30d,
                'stale_workflows'               => $staleWorkflows,
            ],
            'error' => null,
            'trace_id' => $traceId,
        ]);
        exit;

    // ── Hook Performance ─────────────────────────────────────────────────────
    case 'hook_performance':
        $platform = (string) ($_GET['platform'] ?? '');
        $sql = "SELECT h.hook_type, h.platform, hp.platform AS perf_platform,
                       AVG(hp.views) AS avg_views, AVG(hp.watch_time_seconds) AS avg_watch_time,
                       AVG(hp.engagement_rate) AS avg_engagement, COUNT(*) AS sample_count
                FROM hook_performance hp
                JOIN hooks h ON h.id = hp.hook_id
                WHERE hp.recorded_at >= DATE_SUB(NOW(), INTERVAL :days DAY)";
        $params = [':days' => $days];
        if ($platform !== '') {
            $sql .= ' AND hp.platform = :platform';
            $params[':platform'] = $platform;
        }
        $sql .= ' GROUP BY h.hook_type, h.platform, hp.platform ORDER BY avg_engagement DESC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(), 'error' => null, 'trace_id' => $traceId]);
        exit;

    // ── Optimizer Outcomes ───────────────────────────────────────────────────
    case 'optimizer_outcomes':
        $st = $pdo->prepare(
            "SELECT target_type, decision,
                    COUNT(*) AS run_count,
                    AVG(score) AS avg_score,
                    AVG(confidence) AS avg_confidence
             FROM optimizer_runs
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
             GROUP BY target_type, decision
             ORDER BY run_count DESC"
        );
        $st->execute([':days' => $days]);
        $rows = $st->fetchAll();

        $varSt = $pdo->prepare(
            "SELECT SUM(accepted = 1) AS accepted, COUNT(*) AS total
             FROM optimizer_variants ov
             JOIN optimizer_runs r ON r.id = ov.run_id
             WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)"
        );
        $varSt->execute([':days' => $days]);
        $varRow = $varSt->fetch();

        echo json_encode([
            'ok'   => true,
            'data' => [
                'by_decision'         => $rows,
                'variant_accepted'    => (int)   ($varRow['accepted'] ?? 0),
                'variant_total'       => (int)   ($varRow['total']    ?? 0),
            ],
            'error'    => null,
            'trace_id' => $traceId,
        ]);
        exit;

    // ── Posting Health ───────────────────────────────────────────────────────
    case 'posting_health':
        $healthDays = max(1, (int) ($_GET['days'] ?? 7));
        $st = $pdo->prepare(
            "SELECT status, COUNT(*) AS cnt
             FROM social_post_queue
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
             GROUP BY status"
        );
        $st->execute([':days' => $healthDays]);
        $breakdown = [];
        $total     = 0;
        foreach ($st->fetchAll() as $row) {
            $breakdown[$row['status']] = (int) $row['cnt'];
            $total += (int) $row['cnt'];
        }
        $successRate = ($total > 0 && isset($breakdown['sent']))
            ? round($breakdown['sent'] / $total * 100, 1) : 0;

        echo json_encode([
            'ok'   => true,
            'data' => ['breakdown' => $breakdown, 'total' => $total, 'success_rate' => $successRate],
            'error'    => null,
            'trace_id' => $traceId,
        ]);
        exit;

    // ── Workflow Bottlenecks ─────────────────────────────────────────────────
    case 'workflow_bottlenecks':
        $thresholdDays = max(1, (int) ($_GET['days'] ?? 7));
        $st = $pdo->prepare(
            "SELECT entity_type, to_state AS stuck_state, COUNT(*) AS stuck_count,
                    MIN(transitioned_at) AS oldest_entry, MAX(transitioned_at) AS newest_entry
             FROM content_state_transitions t1
             WHERE t1.id = (
                 SELECT id FROM content_state_transitions t2
                   WHERE t2.entity_type = t1.entity_type AND t2.entity_id = t1.entity_id
                   ORDER BY transitioned_at DESC LIMIT 1
             )
             AND to_state NOT IN ('published','archived')
             AND transitioned_at < DATE_SUB(NOW(), INTERVAL :days DAY)
             GROUP BY entity_type, to_state
             ORDER BY stuck_count DESC"
        );
        $st->execute([':days' => $thresholdDays]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(), 'error' => null, 'trace_id' => $traceId]);
        exit;

    // ── AI Costs ─────────────────────────────────────────────────────────────
    case 'ai_costs':
        $st = $pdo->prepare(
            "SELECT feature, model, COUNT(*) AS call_count,
                    SUM(estimated_cost_usd) AS total_cost_usd,
                    AVG(estimated_cost_usd) AS avg_cost_usd,
                    SUM(tokens_used) AS total_tokens
             FROM ai_usage_costs
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
             GROUP BY feature, model
             ORDER BY total_cost_usd DESC"
        );
        $st->execute([':days' => $days]);
        $rows = $st->fetchAll();

        $totSt = $pdo->prepare(
            "SELECT COALESCE(SUM(estimated_cost_usd), 0) FROM ai_usage_costs
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)"
        );
        $totSt->execute([':days' => $days]);
        $grandTotal = (float) $totSt->fetchColumn();

        echo json_encode([
            'ok'   => true,
            'data' => ['breakdown' => $rows, 'total_cost_usd' => $grandTotal],
            'error'    => null,
            'trace_id' => $traceId,
        ]);
        exit;

    // ── Trend Effectiveness ──────────────────────────────────────────────────
    case 'trend_effectiveness':
        $st = $pdo->prepare(
            "SELECT tc.id AS cluster_id, tc.label, tc.trend_score,
                    c.id AS case_id, c.title AS case_title, c.status AS case_status,
                    tc.created_at AS clustered_at
             FROM trend_clusters tc
             LEFT JOIN cases c ON c.trend_cluster_id = tc.id
             WHERE tc.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
             ORDER BY tc.trend_score DESC"
        );
        $st->execute([':days' => $days]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll(), 'error' => null, 'trace_id' => $traceId]);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'data' => null, 'error' => 'Unknown action', 'trace_id' => $traceId]);
        exit;
}

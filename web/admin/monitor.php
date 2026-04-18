<?php
/**
 * PTMD Admin — Monitor
 *
 * Unified view for:
 *  — Pipeline health cards (failed posts, pending queue, events today, sync status)
 *  — Clip library & overlay batch pipeline status
 *  — Social post queue with external metrics (once platform APIs are live)
 *  — Site engagement analytics (page views, video plays, top episodes)
 *  — Social dispatch log
 *  — Manual sync trigger
 */

$pageTitle      = 'Monitor | PTMD Admin';
$activePage     = 'monitor';
$pageHeading    = 'Monitor';
$pageSubheading = 'Pipeline health, social post performance, and site engagement analytics.';
$pageActions    = '<a href="/admin/posts.php" class="btn btn-ptmd-outline btn-sm">'
                . '<i class="fa-solid fa-calendar-check me-2"></i>Social Queue</a>';

include __DIR__ . '/_admin_head.php';

require_once __DIR__ . '/../inc/analytics.php';

$pdo = get_db();

// ── Date range filter (for analytics section) ─────────────────────────────────
$to   = date('Y-m-d');
$from = date('Y-m-d', strtotime('-7 days'));

if (!empty($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'])) {
    $from = $_GET['date_from'];
}
if (!empty($_GET['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'])) {
    $to = $_GET['date_to'];
}
if ($from > $to) {
    $from = $to;
}

// ── Platform filter (for social performance section) ──────────────────────────
$filterPlatform = trim((string) ($_GET['platform'] ?? ''));

// ── Data fetching ─────────────────────────────────────────────────────────────
$health       = $pdo ? get_monitor_health($pdo)          : [];
$clipStatus   = $pdo ? get_clip_pipeline_summary($pdo)   : [];
$queueStatus  = $pdo ? get_queue_status_summary($pdo)    : [];
$queueMetrics = $pdo ? get_queue_with_metrics($pdo, 100, $filterPlatform ?: null) : [];
$dispatchLogs = $pdo ? get_recent_dispatch_logs($pdo, 30) : [];
$topEpisodes  = $pdo ? get_top_episodes_by_views($pdo, 10) : [];

// Recent overlay batch jobs
$batchJobs = [];
if ($pdo) {
    try {
        $batchJobs = $pdo->query(
            'SELECT * FROM overlay_batch_jobs ORDER BY created_at DESC LIMIT 5'
        )->fetchAll();
    } catch (\Throwable $e) {
        // Table may not exist on fresh installs; graceful degradation
    }
}

// Site analytics totals for the selected date range (from raw events)
$analyticsTotal = [
    'page_views'      => 0,
    'video_plays'     => 0,
    'video_completes' => 0,
    'link_clicks'     => 0,
    'unique_sessions' => 0,
];
if ($pdo) {
    try {
        $row = $pdo->prepare(
            'SELECT
                 SUM(event_type = "page_view")      AS page_views,
                 SUM(event_type = "video_play")     AS video_plays,
                 SUM(event_type = "video_complete") AS video_completes,
                 SUM(event_type = "link_click")     AS link_clicks,
                 COUNT(DISTINCT session_hash)        AS unique_sessions
             FROM site_analytics_events
             WHERE DATE(created_at) BETWEEN :from AND :to'
        );
        $row->execute(['from' => $from, 'to' => $to]);
        $totals = $row->fetch();
        if ($totals) {
            $analyticsTotal = [
                'page_views'      => (int) ($totals['page_views']      ?? 0),
                'video_plays'     => (int) ($totals['video_plays']     ?? 0),
                'video_completes' => (int) ($totals['video_completes'] ?? 0),
                'link_clicks'     => (int) ($totals['link_clicks']     ?? 0),
                'unique_sessions' => (int) ($totals['unique_sessions'] ?? 0),
            ];
        }
    } catch (\Throwable $e) {
        // site_analytics_events may not exist yet; graceful degradation
    }
}

$platforms = ['', 'YouTube', 'YouTube Shorts', 'TikTok', 'Instagram Reels', 'Facebook Reels', 'X'];

// Determine whether any platform metrics have been collected
$hasMetrics = false;
foreach ($queueMetrics as $row) {
    if ($row['snapped_at'] !== null) {
        $hasMetrics = true;
        break;
    }
}

/** Small helper for status badge colour class */
function monitor_status_class(string $status): string
{
    return match ($status) {
        'completed', 'posted', 'done', 'ready', 'approved'  => 'ptmd-status-published',
        'processing', 'running', 'queued', 'scheduled'       => 'ptmd-status-draft',
        'failed', 'blocked'                                   => 'ptmd-status-archived',
        default                                               => 'ptmd-status-draft',
    };
}
?>

<div class="ptmd-screen-analytics">
<!-- ── Health cards ──────────────────────────────────────────────────────────── -->
<div class="row g-4 mb-5">

    <!-- Failed Posts (24 h) -->
    <div class="col-6 col-lg-3">
        <div class="ptmd-card-stat">
            <div class="stat-icon" style="background:rgba(193,18,31,0.12)">
                <i class="fa-solid fa-circle-xmark" style="color:var(--ptmd-error)"></i>
            </div>
            <div class="stat-value" style="color:<?php echo ($health['failed_posts_24h'] ?? 0) > 0 ? 'var(--ptmd-error)' : 'var(--ptmd-teal)'; ?>">
                <?php ee((string) ($health['failed_posts_24h'] ?? 0)); ?>
            </div>
            <div class="stat-label">Failed Posts (24 h)</div>
        </div>
    </div>

    <!-- Pending Queue -->
    <div class="col-6 col-lg-3">
        <div class="ptmd-card-stat">
            <div class="stat-icon" style="background:rgba(255,214,10,0.15)">
                <i class="fa-solid fa-hourglass-half ptmd-text-yellow"></i>
            </div>
            <div class="stat-value ptmd-text-yellow"><?php ee((string) ($health['pending_queue'] ?? 0)); ?></div>
            <div class="stat-label">Pending Queue Items</div>
        </div>
    </div>

    <!-- Events Today -->
    <div class="col-6 col-lg-3">
        <div class="ptmd-card-stat">
            <div class="stat-icon" style="background:rgba(46,196,182,0.15)">
                <i class="fa-solid fa-arrow-trend-up ptmd-text-teal"></i>
            </div>
            <div class="stat-value ptmd-text-teal"><?php ee((string) ($health['events_today'] ?? 0)); ?></div>
            <div class="stat-label">Site Events Today</div>
        </div>
    </div>

    <!-- Sync Status -->
    <div class="col-6 col-lg-3">
        <div class="ptmd-card-stat">
            <?php
            $syncStatus = $health['last_sync_status'] ?? null;
            $syncColor  = $syncStatus === 'completed' ? 'var(--ptmd-teal)' : 'var(--ptmd-error)';
            if ($syncStatus === null) {
                $syncColor = 'var(--ptmd-muted)';
            }
            ?>
            <div class="stat-icon" style="background:rgba(46,196,182,0.1)">
                <i class="fa-solid fa-arrows-rotate" style="color:<?php echo $syncColor; ?>"></i>
            </div>
            <div class="stat-value" style="font-size:var(--text-sm);color:<?php echo $syncColor; ?>">
                <?php echo $syncStatus !== null ? e(ucfirst($syncStatus)) : '—'; ?>
            </div>
            <div class="stat-label">
                Last Sync
                <?php if (!empty($health['last_sync_at'])): ?>
                    <span class="ptmd-muted" style="display:block;font-size:var(--text-xs)">
                        <?php echo e(date('M j, g:ia', strtotime($health['last_sync_at']))); ?>
                    </span>
                <?php endif; ?>
                <?php if ($health['stale_sync'] ?? false): ?>
                    <span style="color:var(--ptmd-error);font-size:var(--text-xs)"> ⚠ stale</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- ── Sync control ──────────────────────────────────────────────────────────── -->
<div class="ptmd-panel p-lg mb-5">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <h2 class="h6 mb-1">
                <i class="fa-solid fa-arrows-rotate me-2 ptmd-text-teal"></i>Analytics Sync
            </h2>
            <p class="ptmd-muted small mb-0">
                Refreshes external social metrics for all posted queue items and rolls up today's site event totals.
                Platform metrics are stubs until API credentials are configured in
                <a href="/admin/settings.php">Settings</a>.
            </p>
        </div>
        <button id="runSyncBtn" class="btn btn-ptmd-teal">
            <i class="fa-solid fa-arrows-rotate me-2"></i>Run Sync Now
        </button>
    </div>
</div>

<!-- ── Pipeline status ───────────────────────────────────────────────────────── -->
<div class="row g-4 mb-5">

    <!-- Clip Library -->
    <div class="col-lg-6">
        <div class="ptmd-panel p-lg h-100">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="h6 mb-0">
                    <i class="fa-solid fa-scissors me-2 ptmd-text-teal"></i>Clip Library
                </h2>
                <a href="/admin/video-processor.php" class="btn btn-ptmd-ghost btn-sm">
                    Manage <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
            <?php
            $totalClips = array_sum($clipStatus);
            ?>
            <?php if ($totalClips > 0): ?>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ($clipStatus as $st => $cnt): ?>
                        <?php if ($cnt === 0) continue; ?>
                        <div class="text-center" style="min-width:70px">
                            <div class="fw-700 mb-1" style="font-size:var(--text-2xl)">
                                <?php ee((string) $cnt); ?>
                            </div>
                            <span class="ptmd-status <?php echo monitor_status_class($st); ?>" style="font-size:var(--text-xs)">
                                <?php ee($st); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="ptmd-muted small">No clips yet. <a href="/admin/video-processor.php">Upload your first video</a>.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Social Queue Breakdown -->
    <div class="col-lg-6">
        <div class="ptmd-panel p-lg h-100">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="h6 mb-0">
                    <i class="fa-solid fa-calendar-check me-2 ptmd-text-teal"></i>Social Queue
                </h2>
                <a href="/admin/posts.php" class="btn btn-ptmd-ghost btn-sm">
                    Manage <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
            <?php
            $totalQueue = array_sum($queueStatus);
            ?>
            <?php if ($totalQueue > 0): ?>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ($queueStatus as $st => $cnt): ?>
                        <?php if ($cnt === 0) continue; ?>
                        <div class="text-center" style="min-width:70px">
                            <div class="fw-700 mb-1" style="font-size:var(--text-2xl)">
                                <?php ee((string) $cnt); ?>
                            </div>
                            <span class="ptmd-status <?php echo monitor_status_class($st); ?>" style="font-size:var(--text-xs)">
                                <?php ee($st); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="ptmd-muted small">Queue is empty. <a href="/admin/posts.php">Add a post</a>.</p>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Overlay Batch Jobs -->
<?php if (!empty($batchJobs)): ?>
<div class="ptmd-panel p-lg mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h6 mb-0">
            <i class="fa-solid fa-layer-group me-2 ptmd-text-teal"></i>Recent Overlay Batches
        </h2>
        <a href="/admin/overlay-tool.php" class="btn btn-ptmd-ghost btn-sm">
            Overlay Tool <i class="fa-solid fa-arrow-right ms-1"></i>
        </a>
    </div>
    <div class="table-responsive">
        <table class="ptmd-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Label</th>
                    <th>Clips</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($batchJobs as $job): ?>
                    <tr>
                        <td class="ptmd-muted">#<?php ee((string) $job['id']); ?></td>
                        <td><?php ee($job['label']); ?></td>
                        <td class="ptmd-muted"><?php echo e($job['done_items']); ?> / <?php echo e($job['total_items']); ?></td>
                        <td>
                            <span class="ptmd-status <?php echo monitor_status_class($job['status']); ?>">
                                <?php ee($job['status']); ?>
                            </span>
                        </td>
                        <td class="ptmd-muted" style="font-size:var(--text-xs)">
                            <?php echo e(date('M j, Y g:ia', strtotime($job['created_at']))); ?>
                        </td>
                        <td>
                            <a href="/admin/overlay-tool.php?view_job=<?php ee((string) $job['id']); ?>"
                               class="btn btn-ptmd-ghost btn-sm" data-tippy-content="View items">
                                <i class="fa-solid fa-list"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Social post performance ───────────────────────────────────────────────── -->
<div class="ptmd-panel p-lg mb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <h2 class="h6 mb-0">
            <i class="fa-solid fa-chart-bar me-2 ptmd-text-teal"></i>Social Post Performance
        </h2>
        <!-- Platform filter -->
        <form method="get" action="/admin/monitor.php" class="d-flex gap-2 align-items-center">
            <?php if ($from !== date('Y-m-d', strtotime('-7 days'))): ?>
                <input type="hidden" name="date_from" value="<?php ee($from); ?>">
                <input type="hidden" name="date_to"   value="<?php ee($to); ?>">
            <?php endif; ?>
            <select name="platform" class="form-select form-select-sm" style="width:auto"
                    onchange="this.form.submit()">
                <option value="">All Platforms</option>
                <?php foreach (array_filter($platforms) as $p): ?>
                    <option value="<?php ee($p); ?>" <?php echo $filterPlatform === $p ? 'selected' : ''; ?>>
                        <?php ee($p); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if (!$hasMetrics): ?>
        <div class="ptmd-muted small mb-3">
            <i class="fa-solid fa-circle-info me-1"></i>
            External metrics (views, likes, etc.) are not yet available — platform API integrations are pending.
            Post status and dispatch data are shown below.
        </div>
    <?php endif; ?>

    <?php if (!empty($queueMetrics)): ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>Platform</th>
                        <th>Content</th>
                        <th>Scheduled</th>
                        <th>Status</th>
                        <th>External ID</th>
                        <?php if ($hasMetrics): ?>
                            <th>Views</th>
                            <th>Likes</th>
                            <th>Comments</th>
                            <th>Last Synced</th>
                        <?php endif; ?>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($queueMetrics as $item): ?>
                        <tr>
                            <td>
                                <span class="fw-500" style="font-size:var(--text-sm)"><?php ee($item['platform']); ?></span>
                            </td>
                            <td style="font-size:var(--text-xs)">
                                <?php if (!empty($item['episode_title'])): ?>
                                    <span class="ptmd-muted">Episode: </span><?php ee($item['episode_title']); ?>
                                <?php elseif (!empty($item['clip_label'])): ?>
                                    <span class="ptmd-muted">Clip: </span><?php ee($item['clip_label']); ?>
                                <?php else: ?>
                                    <span class="ptmd-muted">—</span>
                                <?php endif; ?>
                                <?php if (!empty($item['content_type'])): ?>
                                    <br><span class="ptmd-badge-muted"><?php ee($item['content_type']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php echo $item['scheduled_for']
                                    ? e(date('M j, Y g:ia', strtotime($item['scheduled_for'])))
                                    : '—'; ?>
                            </td>
                            <td>
                                <span class="ptmd-status <?php echo monitor_status_class($item['status']); ?>">
                                    <?php ee($item['status']); ?>
                                </span>
                            </td>
                            <td style="font-size:var(--text-xs)">
                                <?php if (!empty($item['external_post_id'])): ?>
                                    <code style="font-size:var(--text-xs);word-break:break-all"><?php ee($item['external_post_id']); ?></code>
                                <?php else: ?>
                                    <span class="ptmd-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($hasMetrics): ?>
                                <td><?php echo $item['views']    !== null ? e(number_format((int) $item['views']))    : '<span class="ptmd-muted">—</span>'; ?></td>
                                <td><?php echo $item['likes']    !== null ? e(number_format((int) $item['likes']))    : '<span class="ptmd-muted">—</span>'; ?></td>
                                <td><?php echo $item['comments'] !== null ? e(number_format((int) $item['comments'])) : '<span class="ptmd-muted">—</span>'; ?></td>
                                <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                    <?php echo $item['snapped_at']
                                        ? e(date('M j, g:ia', strtotime($item['snapped_at'])))
                                        : '—'; ?>
                                </td>
                            <?php endif; ?>
                            <td style="font-size:var(--text-xs);color:var(--ptmd-error);max-width:200px">
                                <?php ee($item['last_error'] ?? ''); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="ptmd-muted small">No queue items found<?php echo $filterPlatform ? ' for ' . e($filterPlatform) : ''; ?>.</p>
    <?php endif; ?>
</div>

<!-- ── Site Analytics ────────────────────────────────────────────────────────── -->
<div class="ptmd-panel p-lg mb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <h2 class="h6 mb-0">
            <i class="fa-solid fa-eye me-2 ptmd-text-teal"></i>Site Analytics
        </h2>
        <!-- Date range filter -->
        <form method="get" action="/admin/monitor.php" class="d-flex gap-2 align-items-center flex-wrap">
            <?php if ($filterPlatform !== ''): ?>
                <input type="hidden" name="platform" value="<?php ee($filterPlatform); ?>">
            <?php endif; ?>
            <input type="date" name="date_from" value="<?php ee($from); ?>"
                   class="form-control form-control-sm" style="width:auto">
            <span class="ptmd-muted small">to</span>
            <input type="date" name="date_to" value="<?php ee($to); ?>"
                   class="form-control form-control-sm" style="width:auto">
            <button type="submit" class="btn btn-ptmd-outline btn-sm">
                <i class="fa-solid fa-filter me-1"></i>Filter
            </button>
        </form>
    </div>

    <!-- Summary cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="ptmd-card-stat" style="padding:1rem">
                <div class="stat-value ptmd-text-teal" style="font-size:var(--text-2xl)">
                    <?php echo e(number_format($analyticsTotal['page_views'])); ?>
                </div>
                <div class="stat-label">Page Views</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="ptmd-card-stat" style="padding:1rem">
                <div class="stat-value" style="font-size:var(--text-2xl);color:var(--ptmd-yellow)">
                    <?php echo e(number_format($analyticsTotal['unique_sessions'])); ?>
                </div>
                <div class="stat-label">Unique Sessions</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="ptmd-card-stat" style="padding:1rem">
                <div class="stat-value" style="font-size:var(--text-2xl);color:#c084fc">
                    <?php echo e(number_format($analyticsTotal['video_plays'])); ?>
                </div>
                <div class="stat-label">Video Plays</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="ptmd-card-stat" style="padding:1rem">
                <div class="stat-value" style="font-size:var(--text-2xl);color:var(--ptmd-teal)">
                    <?php echo e(number_format($analyticsTotal['video_completes'])); ?>
                </div>
                <div class="stat-label">Video Completes</div>
            </div>
        </div>
    </div>

    <?php if ($analyticsTotal['page_views'] === 0): ?>
        <div class="ptmd-muted small">
            <i class="fa-solid fa-circle-info me-1"></i>
            No events recorded for this date range.
            Events are captured automatically when visitors browse the public site.
            Run a <strong>Sync</strong> to roll up any buffered data.
        </div>
    <?php endif; ?>

    <!-- Top episodes table -->
    <?php if (!empty($topEpisodes)): ?>
        <hr class="ptmd-divider my-4">
        <h3 class="h6 mb-3 ptmd-muted" style="text-transform:uppercase;letter-spacing:.06em;font-size:var(--text-xs)">
            Top Episodes — Last 30 Days
        </h3>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>Episode</th>
                        <th>Page Views</th>
                        <th>Video Plays</th>
                        <th>Completions</th>
                        <th>Link Clicks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topEpisodes as $ep): ?>
                        <tr>
                            <td>
                                <a href="/admin/episodes.php?edit=<?php ee((string) $ep['episode_id']); ?>"
                                   class="fw-500 ptmd-text-muted">
                                    <?php ee($ep['title']); ?>
                                </a>
                            </td>
                            <td><?php echo e(number_format((int) $ep['total_views'])); ?></td>
                            <td><?php echo e(number_format((int) $ep['total_plays'])); ?></td>
                            <td><?php echo e(number_format((int) $ep['total_completes'])); ?></td>
                            <td><?php echo e(number_format((int) $ep['total_clicks'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ── Dispatch log ───────────────────────────────────────────────────────────── -->
<div class="ptmd-panel p-lg mb-5">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-clock-rotate-left me-2 ptmd-text-teal"></i>Recent Dispatch Log
        <span class="ptmd-muted" style="font-size:var(--text-xs);font-weight:400;margin-left:.5rem">Last 30 entries</span>
    </h2>

    <?php if (!empty($dispatchLogs)): ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Platform</th>
                        <th>Content</th>
                        <th>Status</th>
                        <th>External ID</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dispatchLogs as $log): ?>
                        <tr>
                            <td class="ptmd-muted">#<?php ee((string) $log['queue_id']); ?></td>
                            <td style="font-size:var(--text-sm)"><?php ee($log['platform']); ?></td>
                            <td style="font-size:var(--text-xs)">
                                <?php if (!empty($log['episode_title'])): ?>
                                    <?php ee($log['episode_title']); ?>
                                <?php elseif (!empty($log['clip_label'])): ?>
                                    <?php ee($log['clip_label']); ?>
                                <?php elseif (!empty($log['content_type'])): ?>
                                    <span class="ptmd-muted"><?php ee($log['content_type']); ?></span>
                                <?php else: ?>
                                    <span class="ptmd-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="ptmd-status <?php echo monitor_status_class($log['status']); ?>">
                                    <?php ee($log['status']); ?>
                                </span>
                            </td>
                            <td style="font-size:var(--text-xs)">
                                <?php ee($log['external_post_id'] ?? '—'); ?>
                            </td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php echo e(date('M j, Y g:ia', strtotime($log['created_at']))); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="ptmd-muted small">No dispatch log entries yet. Posts will appear here after they are sent.</p>
    <?php endif; ?>
</div>

<?php
$extraScripts = '<script>
\'use strict\';

document.getElementById(\'runSyncBtn\')?.addEventListener(\'click\', async function () {
    const btn = this;
    const fd  = new FormData();
    fd.set(\'csrf_token\', ' . json_encode(csrf_token()) . ');

    btn.disabled = true;
    btn.innerHTML = \'<i class="fa-solid fa-spinner fa-spin me-2"></i>Syncing\u2026\';

    try {
        const res  = await fetch(\'/api/sync_social_metrics.php\', {
            method: \'POST\',
            credentials: \'same-origin\',
            body: fd,
        });
        const data = await res.json();

        if (data.ok) {
            const msg = `Sync complete \u2014 ${data.synced} synced, ${data.skipped} skipped.`;
            window.PTMDToast?.success(msg);
            setTimeout(() => location.reload(), 2000);
        } else {
            window.PTMDToast?.error(data.error ?? \'Sync failed.\');
        }
    } catch (err) {
        window.PTMDToast?.error(\'Network error during sync.\');
    } finally {
        btn.disabled = false;
        btn.innerHTML = \'<i class="fa-solid fa-arrows-rotate me-2"></i>Run Sync Now\';
    }
});
</script>';
?>

</div>
<?php include __DIR__ . '/_admin_footer.php'; ?>

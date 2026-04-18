<?php
/**
 * PTMD — Analytics & Monitoring Helpers
 *
 * Functions used by the monitor page, the sync API, and the track-event API.
 *
 * record_analytics_event()   — write a raw telemetry row to site_analytics_events
 * rollup_daily_analytics()   — aggregate raw events into site_analytics_daily
 * get_monitor_health()       — health counts for the monitor page header cards
 * get_clip_pipeline_summary() — video_clips status breakdown
 * get_queue_status_summary() — social_post_queue status breakdown
 * get_recent_dispatch_logs() — last N dispatch log entries with context
 * get_posted_with_metrics()  — posted queue items joined to latest snapshot
 * get_site_analytics_range() — site_analytics_daily rows for a date range
 * get_top_episodes_by_views() — top N episodes by page views in last 30 days
 * run_social_metrics_sync()  — orchestrate a full metrics sync + daily rollup
 * fetch_platform_metrics()   — route to per-platform stub (Phase B: real APIs)
 */

// ---------------------------------------------------------------------------
// SESSION HASHING  (privacy-preserving; no raw IP stored)
// ---------------------------------------------------------------------------

/**
 * Return a daily-salted hash that identifies a visitor session without
 * storing any personally identifiable information.
 */
function analytics_session_hash(): string
{
    $ip   = $_SERVER['REMOTE_ADDR']      ?? '';
    $ua   = $_SERVER['HTTP_USER_AGENT']  ?? '';
    $salt = date('Y-m-d');
    return hash('sha256', $ip . '|' . $ua . '|' . $salt);
}

// ---------------------------------------------------------------------------
// EVENT RECORDING
// ---------------------------------------------------------------------------

/**
 * Insert a single raw analytics event.
 *
 * @param string   $eventType  One of: page_view | video_play | video_complete | link_click
 * @param int|null $episodeId  Episode associated with the event (if any)
 * @param int|null $clipId     Clip associated with the event (if any)
 * @param array    $extra      Small key→scalar map stored as JSON
 */
function record_analytics_event(
    string $eventType,
    ?int   $episodeId = null,
    ?int   $clipId    = null,
    array  $extra     = []
): bool {
    static $allowed = ['page_view', 'video_play', 'video_complete', 'link_click'];
    if (!in_array($eventType, $allowed, true)) {
        return false;
    }

    $pdo = get_db();
    if (!$pdo) {
        return false;
    }

    $referrer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if (strlen($referrer) > 512) {
        $referrer = substr($referrer, 0, 512);
    }

    try {
        $pdo->prepare(
            'INSERT INTO site_analytics_events
             (event_type, episode_id, clip_id, session_hash, referrer, extra_json, created_at)
             VALUES (:type, :eid, :cid, :sess, :ref, :extra, NOW())'
        )->execute([
            'type'  => $eventType,
            'eid'   => $episodeId,
            'cid'   => $clipId,
            'sess'  => analytics_session_hash(),
            'ref'   => $referrer !== '' ? $referrer : null,
            'extra' => $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null,
        ]);
        return true;
    } catch (\Throwable $e) {
        error_log('[PTMD Analytics] record_analytics_event failed: ' . $e->getMessage());
        return false;
    }
}

// ---------------------------------------------------------------------------
// DAILY ROLLUP
// ---------------------------------------------------------------------------

/**
 * Aggregate raw events for $date (Y-m-d) into site_analytics_daily.
 * Only episode-level rows are stored; site-wide totals are queried on-the-fly.
 * This function is idempotent — safe to run multiple times for the same date.
 */
function rollup_daily_analytics(PDO $pdo, string $date): void
{
    try {
        $pdo->prepare(
            'INSERT INTO site_analytics_daily
                 (stat_date, episode_id, page_views, unique_sessions, video_plays, video_completes, link_clicks)
             SELECT
                 :date,
                 episode_id,
                 SUM(event_type = "page_view"),
                 COUNT(DISTINCT session_hash),
                 SUM(event_type = "video_play"),
                 SUM(event_type = "video_complete"),
                 SUM(event_type = "link_click")
             FROM site_analytics_events
             WHERE DATE(created_at) = :date2
               AND episode_id IS NOT NULL
             GROUP BY episode_id
             ON DUPLICATE KEY UPDATE
                 page_views      = VALUES(page_views),
                 unique_sessions = VALUES(unique_sessions),
                 video_plays     = VALUES(video_plays),
                 video_completes = VALUES(video_completes),
                 link_clicks     = VALUES(link_clicks)'
        )->execute(['date' => $date, 'date2' => $date]);
    } catch (\Throwable $e) {
        error_log('[PTMD Analytics] rollup_daily_analytics failed for ' . $date . ': ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// MONITORING DATA QUERIES
// ---------------------------------------------------------------------------

/**
 * Health summary counts for the monitor page header cards.
 *
 * @return array{
 *   failed_posts_24h: int,
 *   failed_clips: int,
 *   pending_queue: int,
 *   last_sync_status: string|null,
 *   last_sync_at: string|null,
 *   events_today: int,
 *   stale_sync: bool
 * }
 */
function get_monitor_health(PDO $pdo): array
{
    $health = [
        'failed_posts_24h' => 0,
        'failed_clips'     => 0,
        'pending_queue'    => 0,
        'last_sync_status' => null,
        'last_sync_at'     => null,
        'events_today'     => 0,
        'stale_sync'       => false,
    ];

    try {
        $health['failed_posts_24h'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM social_post_queue
              WHERE status = "failed" AND updated_at >= NOW() - INTERVAL 24 HOUR'
        )->fetchColumn();

        $health['failed_clips'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM video_clips WHERE status = "processing"'
        )->fetchColumn();

        $health['pending_queue'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM social_post_queue WHERE status IN ("queued","scheduled")'
        )->fetchColumn();

        $lastSync = $pdo->query(
            'SELECT status, finished_at FROM analytics_sync_runs
              ORDER BY started_at DESC LIMIT 1'
        )->fetch();

        if ($lastSync) {
            $health['last_sync_status'] = $lastSync['status'];
            $health['last_sync_at']     = $lastSync['finished_at'];
            // Stale = no successful completion in the last 26 hours
            $completedRecently = $lastSync['status'] === 'completed'
                && $lastSync['finished_at'] !== null
                && strtotime($lastSync['finished_at']) >= time() - 93600;
            $health['stale_sync'] = !$completedRecently;
        } else {
            $health['stale_sync'] = true;
        }

        $health['events_today'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM site_analytics_events WHERE DATE(created_at) = CURDATE()'
        )->fetchColumn();
    } catch (\Throwable $e) {
        error_log('[PTMD Analytics] get_monitor_health error: ' . $e->getMessage());
    }

    return $health;
}

/**
 * Clip library status breakdown for the pipeline panel.
 */
function get_clip_pipeline_summary(PDO $pdo): array
{
    $summary = ['raw' => 0, 'processing' => 0, 'ready' => 0, 'queued' => 0, 'posted' => 0];
    try {
        $rows = $pdo->query(
            'SELECT status, COUNT(*) AS cnt FROM video_clips GROUP BY status'
        )->fetchAll();
        foreach ($rows as $row) {
            if (array_key_exists($row['status'], $summary)) {
                $summary[$row['status']] = (int) $row['cnt'];
            }
        }
    } catch (\Throwable $e) {
        error_log('[PTMD Analytics] get_clip_pipeline_summary error: ' . $e->getMessage());
    }
    return $summary;
}

/**
 * Social queue status breakdown for the pipeline panel.
 */
function get_queue_status_summary(PDO $pdo): array
{
    $summary = [
        'draft'     => 0,
        'queued'    => 0,
        'scheduled' => 0,
        'posted'    => 0,
        'failed'    => 0,
        'canceled'  => 0,
    ];
    try {
        $rows = $pdo->query(
            'SELECT status, COUNT(*) AS cnt FROM social_post_queue GROUP BY status'
        )->fetchAll();
        foreach ($rows as $row) {
            if (array_key_exists($row['status'], $summary)) {
                $summary[$row['status']] = (int) $row['cnt'];
            }
        }
    } catch (\Throwable $e) {
        error_log('[PTMD Analytics] get_queue_status_summary error: ' . $e->getMessage());
    }
    return $summary;
}

/**
 * Recent dispatch log entries joined to queue context.
 */
function get_recent_dispatch_logs(PDO $pdo, int $limit = 30): array
{
    $limit = max(1, min(200, $limit));
    try {
        return $pdo->query(
            "SELECT l.id, l.queue_id, l.platform, l.status, l.created_at,
                    q.episode_id, q.clip_id, q.content_type, q.external_post_id,
                    e.title   AS episode_title,
                    vc.label  AS clip_label
             FROM social_post_logs l
             LEFT JOIN social_post_queue q  ON q.id  = l.queue_id
             LEFT JOIN episodes e           ON e.id  = q.episode_id
             LEFT JOIN video_clips vc       ON vc.id = q.clip_id
             ORDER BY l.created_at DESC
             LIMIT {$limit}"
        )->fetchAll();
    } catch (\Throwable $e) {
        error_log('[PTMD Analytics] get_recent_dispatch_logs error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Posted queue items joined to their latest metrics snapshot (if any).
 *
 * @param int         $limit          Max rows to return
 * @param string|null $platform       Filter to a specific platform (empty = all)
 */
function get_posted_with_metrics(PDO $pdo, int $limit = 50, ?string $platform = null): array
{
    $limit = max(1, min(500, $limit));
    $where  = 'q.status = "posted"';
    $params = [];

    if ($platform !== null && $platform !== '') {
        $where          .= ' AND q.platform = :platform';
        $params['platform'] = $platform;
    }

    $sql = "SELECT
                q.id, q.platform, q.content_type, q.scheduled_for, q.status,
                q.external_post_id, q.last_error, q.updated_at,
                e.title   AS episode_title,
                vc.label  AS clip_label,
                s.views, s.likes, s.comments, s.shares,
                s.watch_time_sec, s.impressions, s.snapped_at
            FROM social_post_queue q
            LEFT JOIN episodes e     ON e.id  = q.episode_id
            LEFT JOIN video_clips vc ON vc.id = q.clip_id
            LEFT JOIN social_metrics_snapshots s ON s.queue_id = q.id
                AND s.snapped_at = (
                    SELECT MAX(snapped_at) FROM social_metrics_snapshots
                    WHERE queue_id = q.id
                )
            WHERE {$where}
            ORDER BY q.updated_at DESC
            LIMIT {$limit}";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (\Throwable $e) {
        error_log('[PTMD Analytics] get_posted_with_metrics error: ' . $e->getMessage());
        return [];
    }
}

/**
 * All queue items (any status) for the social performance table,
 * optionally filtered by platform. Posted items get their latest snapshot attached.
 */
function get_queue_with_metrics(PDO $pdo, int $limit = 100, ?string $platform = null): array
{
    $limit  = max(1, min(500, $limit));
    $where  = '1=1';
    $params = [];

    if ($platform !== null && $platform !== '') {
        $where          .= ' AND q.platform = :platform';
        $params['platform'] = $platform;
    }

    $sql = "SELECT
                q.id, q.platform, q.content_type, q.scheduled_for, q.status,
                q.external_post_id, q.last_error, q.updated_at,
                e.title   AS episode_title,
                vc.label  AS clip_label,
                s.views, s.likes, s.comments, s.shares,
                s.watch_time_sec, s.impressions, s.snapped_at
            FROM social_post_queue q
            LEFT JOIN episodes e     ON e.id  = q.episode_id
            LEFT JOIN video_clips vc ON vc.id = q.clip_id
            LEFT JOIN social_metrics_snapshots s ON s.queue_id = q.id
                AND s.snapped_at = (
                    SELECT MAX(snapped_at) FROM social_metrics_snapshots
                    WHERE queue_id = q.id
                )
            WHERE {$where}
            ORDER BY q.updated_at DESC
            LIMIT {$limit}";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (\Throwable $e) {
        error_log('[PTMD Analytics] get_queue_with_metrics error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Per-episode daily rollup rows for a date range.
 *
 * @param int|null $episodeId  If set, return only that episode's rows
 */
function get_site_analytics_range(PDO $pdo, string $from, string $to, ?int $episodeId = null): array
{
    try {
        if ($episodeId !== null) {
            $stmt = $pdo->prepare(
                'SELECT * FROM site_analytics_daily
                  WHERE stat_date BETWEEN :from AND :to
                    AND episode_id = :eid
                  ORDER BY stat_date DESC'
            );
            $stmt->execute(['from' => $from, 'to' => $to, 'eid' => $episodeId]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT * FROM site_analytics_daily
                  WHERE stat_date BETWEEN :from AND :to
                  ORDER BY stat_date DESC'
            );
            $stmt->execute(['from' => $from, 'to' => $to]);
        }
        return $stmt->fetchAll();
    } catch (\Throwable $e) {
        error_log('[PTMD Analytics] get_site_analytics_range error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Top N episodes by total page views in the last 30 days (from daily rollups).
 */
function get_top_episodes_by_views(PDO $pdo, int $limit = 10): array
{
    $limit = max(1, min(100, $limit));
    try {
        return $pdo->query(
            "SELECT
                 sad.episode_id,
                 SUM(sad.page_views)      AS total_views,
                 SUM(sad.video_plays)     AS total_plays,
                 SUM(sad.video_completes) AS total_completes,
                 SUM(sad.link_clicks)     AS total_clicks,
                 e.title
             FROM site_analytics_daily sad
             JOIN episodes e ON e.id = sad.episode_id
             WHERE sad.stat_date >= CURDATE() - INTERVAL 30 DAY
             GROUP BY sad.episode_id, e.title
             ORDER BY total_views DESC
             LIMIT {$limit}"
        )->fetchAll();
    } catch (\Throwable $e) {
        error_log('[PTMD Analytics] get_top_episodes_by_views error: ' . $e->getMessage());
        return [];
    }
}

// ---------------------------------------------------------------------------
// SOCIAL METRICS COLLECTION  (Phase B: replace stubs with real API calls)
// ---------------------------------------------------------------------------

/**
 * Route a posted queue item to the appropriate platform metrics collector.
 * Returns an array of canonical metric fields, or null when no data is available
 * (e.g. API not configured, item has no external_post_id).
 *
 * @return array{views:int,likes:int,comments:int,shares:int,watch_time_sec:int,impressions:int}|null
 */
function fetch_platform_metrics(array $queueItem): ?array
{
    $extId = $queueItem['external_post_id'] ?? '';
    if ($extId === '' || $extId === null) {
        return null;
    }

    $platform = strtolower(str_replace([' ', '/'], '_', $queueItem['platform'] ?? ''));

    return match ($platform) {
        'youtube'         => _fetch_youtube_metrics($queueItem),
        'youtube_shorts'  => _fetch_youtube_metrics($queueItem),
        'tiktok'          => _fetch_tiktok_metrics($queueItem),
        'instagram_reels' => _fetch_instagram_metrics($queueItem),
        'facebook_reels'  => _fetch_facebook_metrics($queueItem),
        'x'               => _fetch_x_metrics($queueItem),
        default           => null,
    };
}

function _fetch_youtube_metrics(array $item): ?array
{
    // TODO (Phase B): YouTube Analytics API v2
    // GET https://youtubeanalytics.googleapis.com/v2/reports
    //     ?ids=channel==mine&metrics=views,likes,comments,shares,estimatedMinutesWatched
    //     &filters=video=={external_post_id}
    // Auth: OAuth2 via Google\Client with youtube.readonly scope.
    error_log('[PTMD Metrics] YouTube metrics stub. Queue ID: ' . $item['id']);
    return null;
}

function _fetch_tiktok_metrics(array $item): ?array
{
    // TODO (Phase B): TikTok Research API / Business Content API
    // POST https://open.tiktokapis.com/v2/video/query/
    //     with video_ids=[external_post_id] and fields=[view_count,like_count,comment_count,share_count]
    error_log('[PTMD Metrics] TikTok metrics stub. Queue ID: ' . $item['id']);
    return null;
}

function _fetch_instagram_metrics(array $item): ?array
{
    // TODO (Phase B): Meta Graph API — /{media-id}/insights
    // GET https://graph.facebook.com/v19.0/{media_id}/insights
    //     ?metric=impressions,reach,plays,likes,comments,shares&access_token=...
    error_log('[PTMD Metrics] Instagram metrics stub. Queue ID: ' . $item['id']);
    return null;
}

function _fetch_facebook_metrics(array $item): ?array
{
    // TODO (Phase B): Meta Graph API — /{video-id}/video_insights
    // GET https://graph.facebook.com/v19.0/{video_id}/video_insights
    //     ?metric=total_video_views,total_video_likes,total_video_comments&access_token=...
    error_log('[PTMD Metrics] Facebook metrics stub. Queue ID: ' . $item['id']);
    return null;
}

function _fetch_x_metrics(array $item): ?array
{
    // TODO (Phase B): X API v2 — GET /2/tweets/{id}?tweet.fields=public_metrics
    // public_metrics includes: retweet_count, reply_count, like_count, impression_count
    error_log('[PTMD Metrics] X metrics stub. Queue ID: ' . $item['id']);
    return null;
}

// ---------------------------------------------------------------------------
// SYNC ORCHESTRATION
// ---------------------------------------------------------------------------

/**
 * Run a full analytics sync:
 *  1. Attempt to fetch external metrics for all posted queue items.
 *  2. Store new snapshots for items where the platform returned data.
 *  3. Run daily rollup for today.
 *
 * Records a row in analytics_sync_runs for observability.
 *
 * @return array{synced:int,failed:int,skipped:int,error?:string}
 */
function run_social_metrics_sync(PDO $pdo): array
{
    $summary = ['synced' => 0, 'failed' => 0, 'skipped' => 0];

    // Log sync start
    $pdo->prepare(
        'INSERT INTO analytics_sync_runs (sync_type, status, started_at)
         VALUES ("social_metrics", "running", NOW())'
    )->execute();
    $runId = (int) $pdo->lastInsertId();

    try {
        $postedItems = $pdo->query(
            'SELECT * FROM social_post_queue
              WHERE status = "posted" AND external_post_id IS NOT NULL
              ORDER BY updated_at DESC
              LIMIT 200'
        )->fetchAll();

        foreach ($postedItems as $item) {
            $metrics = fetch_platform_metrics($item);

            if ($metrics === null) {
                $summary['skipped']++;
                continue;
            }

            try {
                $pdo->prepare(
                    'INSERT INTO social_metrics_snapshots
                     (queue_id, platform, external_post_id, views, likes, comments,
                      shares, watch_time_sec, impressions, extra_json, snapped_at)
                     VALUES
                     (:qid, :platform, :ext_id, :views, :likes, :comments,
                      :shares, :watch_time, :impressions, :extra, NOW())'
                )->execute([
                    'qid'         => (int) $item['id'],
                    'platform'    => $item['platform'],
                    'ext_id'      => $item['external_post_id'],
                    'views'       => (int) ($metrics['views']         ?? 0),
                    'likes'       => (int) ($metrics['likes']         ?? 0),
                    'comments'    => (int) ($metrics['comments']      ?? 0),
                    'shares'      => (int) ($metrics['shares']        ?? 0),
                    'watch_time'  => (int) ($metrics['watch_time_sec'] ?? 0),
                    'impressions' => (int) ($metrics['impressions']   ?? 0),
                    'extra'       => isset($metrics['extra'])
                        ? json_encode($metrics['extra'], JSON_UNESCAPED_UNICODE)
                        : null,
                ]);
                $summary['synced']++;
            } catch (\Throwable $e) {
                $summary['failed']++;
                error_log('[PTMD Metrics] Snapshot insert failed: ' . $e->getMessage());
            }
        }

        // Roll up today's raw events into daily totals
        rollup_daily_analytics($pdo, date('Y-m-d'));

        $pdo->prepare(
            'UPDATE analytics_sync_runs
             SET status = "completed", items_synced = :synced, items_failed = :failed, finished_at = NOW()
             WHERE id = :id'
        )->execute(['synced' => $summary['synced'], 'failed' => $summary['failed'], 'id' => $runId]);
    } catch (\Throwable $e) {
        error_log('[PTMD Metrics] run_social_metrics_sync error: ' . $e->getMessage());
        try {
            $pdo->prepare(
                'UPDATE analytics_sync_runs
                 SET status = "failed", error_message = :err, finished_at = NOW()
                 WHERE id = :id'
            )->execute(['err' => $e->getMessage(), 'id' => $runId]);
        } catch (\Throwable) {
            // Ignore secondary failure
        }
        $summary['error'] = $e->getMessage();
    }

    return $summary;
}

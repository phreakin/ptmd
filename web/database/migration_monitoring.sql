-- ============================================================
-- PTMD Migration — Analytics & Monitoring tables
-- Run AFTER schema.sql on existing installs.
-- For new installs these tables are already in schema.sql.
-- Compatible with MySQL 8+ / MariaDB 10.5+
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Site Analytics Events  (raw first-party telemetry)
-- One row per event fired from the public site.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_analytics_events (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type   VARCHAR(60)   NOT NULL,   -- page_view | video_play | video_complete | link_click
    episode_id   INT UNSIGNED  NULL,
    clip_id      INT UNSIGNED  NULL,
    session_hash VARCHAR(64)   NULL,       -- daily-salted SHA-256(IP|UA|date) — no raw PII stored
    referrer     VARCHAR(512)  NULL,
    extra_json   JSON          NULL,
    created_at   DATETIME      NOT NULL,
    INDEX idx_sae_event_created  (event_type, created_at),
    INDEX idx_sae_episode_created (episode_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Site Analytics Daily  (aggregated per-episode rollups)
-- Populated by run_social_metrics_sync() / rollup_daily_analytics().
-- episode_id is NOT NULL; site-wide totals are queried from raw events.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_analytics_daily (
    id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    stat_date       DATE          NOT NULL,
    episode_id      INT UNSIGNED  NOT NULL,
    page_views      INT UNSIGNED  NOT NULL DEFAULT 0,
    unique_sessions INT UNSIGNED  NOT NULL DEFAULT 0,
    video_plays     INT UNSIGNED  NOT NULL DEFAULT 0,
    video_completes INT UNSIGNED  NOT NULL DEFAULT 0,
    link_clicks     INT UNSIGNED  NOT NULL DEFAULT 0,
    UNIQUE KEY uq_sad_date_episode (stat_date, episode_id),
    INDEX idx_sad_stat_date (stat_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Social Metrics Snapshots
-- One snapshot row per metrics-fetch per queue item.
-- Multiple snapshots allowed over time for trend tracking.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS social_metrics_snapshots (
    id               INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    queue_id         INT UNSIGNED  NOT NULL,
    platform         VARCHAR(80)   NOT NULL,
    external_post_id VARCHAR(255)  NOT NULL,
    views            BIGINT UNSIGNED NOT NULL DEFAULT 0,
    likes            BIGINT UNSIGNED NOT NULL DEFAULT 0,
    comments         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    shares           BIGINT UNSIGNED NOT NULL DEFAULT 0,
    watch_time_sec   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    impressions      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    extra_json       JSON          NULL,
    snapped_at       DATETIME      NOT NULL,
    CONSTRAINT fk_sms_queue FOREIGN KEY (queue_id) REFERENCES social_post_queue(id) ON DELETE CASCADE,
    INDEX idx_sms_queue_snapped   (queue_id, snapped_at),
    INDEX idx_sms_platform_snapped (platform, snapped_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Analytics Sync Runs  (observability for scheduled collectors)
-- One row per run_social_metrics_sync() execution.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS analytics_sync_runs (
    id             INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    sync_type      VARCHAR(60)   NOT NULL,   -- social_metrics | site_rollup
    status         ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    items_synced   INT UNSIGNED  NOT NULL DEFAULT 0,
    items_failed   INT UNSIGNED  NOT NULL DEFAULT 0,
    error_message  TEXT          NULL,
    started_at     DATETIME      NOT NULL,
    finished_at    DATETIME      NULL,
    INDEX idx_asr_type_started (sync_type, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

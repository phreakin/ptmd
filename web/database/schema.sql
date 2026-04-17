-- ============================================================
-- PTMD Database Schema
-- Compatible with MySQL 8+ / MariaDB 10.5+
-- Run this file first, then seed.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Users / Auth
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)      NOT NULL UNIQUE,
    email         VARCHAR(150)     NOT NULL UNIQUE,
    password_hash VARCHAR(255)     NOT NULL,
    role          ENUM('admin','editor') NOT NULL DEFAULT 'admin',
    created_at    DATETIME         NOT NULL,
    updated_at    DATETIME         NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Episodes
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS episodes (
    id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(255)  NOT NULL,
    slug            VARCHAR(255)  NOT NULL UNIQUE,
    excerpt         TEXT,
    body            MEDIUMTEXT,
    thumbnail_image VARCHAR(255)  NULL,
    featured_image  VARCHAR(255)  NULL,
    video_url       VARCHAR(500)  NULL,
    video_file_path VARCHAR(255)  NULL,   -- local upload path relative to /uploads
    duration        VARCHAR(50)   NULL,
    status          ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    published_at    DATETIME      NULL,
    created_at      DATETIME      NOT NULL,
    updated_at      DATETIME      NOT NULL,
    INDEX idx_status_published (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Episode Categories
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS episode_categories (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL UNIQUE,
    slug       VARCHAR(140) NOT NULL UNIQUE,
    created_at DATETIME     NOT NULL,
    updated_at DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Episode Tags
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS episode_tags (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL UNIQUE,
    slug       VARCHAR(140) NOT NULL UNIQUE,
    created_at DATETIME     NOT NULL,
    updated_at DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS episode_tag_map (
    episode_id INT UNSIGNED NOT NULL,
    tag_id     INT UNSIGNED NOT NULL,
    PRIMARY KEY (episode_id, tag_id),
    CONSTRAINT fk_etm_episode FOREIGN KEY (episode_id) REFERENCES episodes(id) ON DELETE CASCADE,
    CONSTRAINT fk_etm_tag     FOREIGN KEY (tag_id)     REFERENCES episode_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Site Settings  (key-value store for all editable config)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_settings (
    id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(150)  NOT NULL UNIQUE,
    setting_value TEXT          NULL,
    setting_type  VARCHAR(30)   NOT NULL DEFAULT 'string',   -- string | bool | int | json | secret
    label         VARCHAR(200)  NULL,    -- human-readable label for admin UI
    group_name    VARCHAR(80)   NULL DEFAULT 'general',
    updated_at    DATETIME      NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Media Library
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS media_library (
    id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    filename      VARCHAR(255)  NOT NULL,
    file_path     VARCHAR(255)  NOT NULL,   -- relative to /uploads
    file_type     VARCHAR(120)  NOT NULL,   -- MIME type
    file_size     BIGINT UNSIGNED NOT NULL,
    category      ENUM('thumbnail','intro','overlay','clip','watermark','logo','other') NOT NULL DEFAULT 'other',
    metadata_json JSON          NULL,
    created_at    DATETIME      NOT NULL,
    updated_at    DATETIME      NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Social Accounts
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS social_accounts (
    id                INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    platform          VARCHAR(80)   NOT NULL,
    handle            VARCHAR(120)  NOT NULL,
    auth_config_json  JSON          NULL,    -- store tokens encrypted or via vault in production
    is_active         TINYINT(1)    NOT NULL DEFAULT 1,
    created_at        DATETIME      NOT NULL,
    updated_at        DATETIME      NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Social Post Schedules  (recurring cadence windows)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS social_post_schedules (
    id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    platform     VARCHAR(80)   NOT NULL,
    content_type VARCHAR(80)   NOT NULL,
    day_of_week  VARCHAR(20)   NOT NULL,
    post_time    TIME          NOT NULL,
    timezone     VARCHAR(100)  NOT NULL DEFAULT 'America/Phoenix',
    is_active    TINYINT(1)    NOT NULL DEFAULT 1,
    created_at   DATETIME      NOT NULL,
    updated_at   DATETIME      NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Social Platform Preferences
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS social_platform_preferences (
    id                     INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    platform               VARCHAR(80)   NOT NULL UNIQUE,
    default_content_type   VARCHAR(80)   NULL,
    default_caption_prefix TEXT          NULL,
    default_hashtags       VARCHAR(255)  NULL,
    default_status         ENUM('draft','queued','scheduled') NOT NULL DEFAULT 'queued',
    is_enabled             TINYINT(1)    NOT NULL DEFAULT 1,
    created_at             DATETIME      NOT NULL,
    updated_at             DATETIME      NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Social Post Queue
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS social_post_queue (
    id               INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    episode_id       INT UNSIGNED   NULL,
    clip_id          INT UNSIGNED   NULL,
    platform         VARCHAR(80)    NOT NULL,
    content_type     VARCHAR(80)    NOT NULL,
    caption          TEXT           NULL,
    asset_path       VARCHAR(255)   NULL,
    scheduled_for    DATETIME       NOT NULL,
    status           ENUM('draft','queued','scheduled','posted','failed','canceled') NOT NULL DEFAULT 'draft',
    external_post_id VARCHAR(255)   NULL,
    last_error       TEXT           NULL,
    created_at       DATETIME       NOT NULL,
    updated_at       DATETIME       NOT NULL,
    CONSTRAINT fk_spq_episode FOREIGN KEY (episode_id) REFERENCES episodes(id) ON DELETE SET NULL,
    INDEX idx_clip_platform (clip_id, platform),
    INDEX idx_status_scheduled (status, scheduled_for)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Social Post Logs  (attempt history)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS social_post_logs (
    id                    INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    queue_id              INT UNSIGNED  NOT NULL,
    platform              VARCHAR(80)   NOT NULL,
    request_payload_json  JSON          NULL,
    response_payload_json JSON          NULL,
    status                VARCHAR(50)   NOT NULL,
    created_at            DATETIME      NOT NULL,
    CONSTRAINT fk_spl_queue FOREIGN KEY (queue_id) REFERENCES social_post_queue(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Chat Messages
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_messages (
    id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(50)   NOT NULL,
    message     TEXT          NOT NULL,
    status      ENUM('approved','flagged','blocked') NOT NULL DEFAULT 'approved',
    emojis_json JSON          NULL,
    ip_hash     VARCHAR(64)   NULL,   -- hashed IP for moderation (not raw)
    created_at  DATETIME      NOT NULL,
    updated_at  DATETIME      NOT NULL,
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Chat Moderation Logs
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_moderation_logs (
    id               INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    chat_message_id  INT UNSIGNED  NOT NULL,
    moderator_id     INT UNSIGNED  NULL,
    action           VARCHAR(50)   NOT NULL,   -- approved | flagged | blocked
    reason           VARCHAR(255)  NULL,
    created_at       DATETIME      NOT NULL,
    CONSTRAINT fk_cml_message FOREIGN KEY (chat_message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_cml_user    FOREIGN KEY (moderator_id)    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- AI Generations  (log every OpenAI call + output)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_generations (
    id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    episode_id      INT UNSIGNED  NULL,
    feature         VARCHAR(80)   NOT NULL,   -- video_ideas | title | keywords | description | caption | thumbnail_concept | episode_field_suggestion
    input_prompt    TEXT          NOT NULL,
    output_text     MEDIUMTEXT    NOT NULL,
    model           VARCHAR(80)   NOT NULL,
    prompt_tokens   INT UNSIGNED  NOT NULL DEFAULT 0,
    response_tokens INT UNSIGNED  NOT NULL DEFAULT 0,
    created_at      DATETIME      NOT NULL,
    CONSTRAINT fk_ag_episode FOREIGN KEY (episode_id) REFERENCES episodes(id) ON DELETE SET NULL,
    INDEX idx_feature (feature),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- AI Video Ideas  (structured ideas generated for U.S./world context)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_video_ideas (
    id                 INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    generation_id      INT UNSIGNED  NULL,
    created_by         INT UNSIGNED  NULL,
    scope              ENUM('us','world','both') NOT NULL DEFAULT 'both',
    context_notes      TEXT          NULL,
    idea_title         VARCHAR(255)  NOT NULL,
    premise            TEXT          NOT NULL,
    suggested_angle    TEXT          NOT NULL,
    rank_order         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at         DATETIME      NOT NULL,
    updated_at         DATETIME      NOT NULL,
    CONSTRAINT fk_avi_generation FOREIGN KEY (generation_id) REFERENCES ai_generations(id) ON DELETE SET NULL,
    CONSTRAINT fk_avi_user       FOREIGN KEY (created_by)    REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_avi_created (created_at),
    INDEX idx_avi_scope_created (scope, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Overlay Batch Jobs  (tracks a batch overlay processing run)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS overlay_batch_jobs (
    id             INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    label          VARCHAR(255)  NOT NULL DEFAULT 'Untitled Batch',
    overlay_path   VARCHAR(255)  NOT NULL,   -- path to overlay PNG relative to /web
    position       VARCHAR(30)   NOT NULL DEFAULT 'bottom-right',  -- top-left | top-right | bottom-left | bottom-right | center | full
    opacity        DECIMAL(3,2)  NOT NULL DEFAULT 1.00,            -- 0.00–1.00
    scale          DECIMAL(5,2)  NOT NULL DEFAULT 100.00,          -- percentage of video width
    status         ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    total_items    INT UNSIGNED  NOT NULL DEFAULT 0,
    done_items     INT UNSIGNED  NOT NULL DEFAULT 0,
    error_message  TEXT          NULL,
    created_by     INT UNSIGNED  NULL,
    created_at     DATETIME      NOT NULL,
    updated_at     DATETIME      NOT NULL,
    CONSTRAINT fk_obj_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Overlay Batch Items  (one row per clip in a batch job)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS overlay_batch_items (
    id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    batch_job_id    INT UNSIGNED  NOT NULL,
    source_path     VARCHAR(255)  NOT NULL,   -- input clip relative to /uploads
    output_path     VARCHAR(255)  NULL,       -- processed clip relative to /uploads/clips
    status          ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
    error_message   TEXT          NULL,
    ffmpeg_command  TEXT          NULL,       -- stored for debugging / replay
    duration_sec    DECIMAL(8,2)  NULL,
    created_at      DATETIME      NOT NULL,
    updated_at      DATETIME      NOT NULL,
    CONSTRAINT fk_obi_job FOREIGN KEY (batch_job_id) REFERENCES overlay_batch_jobs(id) ON DELETE CASCADE,
    INDEX idx_job_status (batch_job_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Video Clips  (short-form clips extracted from episodes)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS video_clips (
    id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    episode_id      INT UNSIGNED  NULL,
    label           VARCHAR(255)  NOT NULL,
    source_path     VARCHAR(255)  NOT NULL,   -- relative to /uploads
    output_path     VARCHAR(255)  NULL,       -- processed version
    start_time      VARCHAR(20)   NULL,       -- HH:MM:SS
    end_time        VARCHAR(20)   NULL,
    duration_sec    DECIMAL(8,2)  NULL,
    platform_target VARCHAR(80)   NULL,       -- youtube_shorts | tiktok | instagram_reels | etc.
    status          ENUM('raw','processing','ready','queued','posted') NOT NULL DEFAULT 'raw',
    created_at      DATETIME      NOT NULL,
    updated_at      DATETIME      NOT NULL,
    CONSTRAINT fk_vc_episode FOREIGN KEY (episode_id) REFERENCES episodes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Admin Copilot — Conversation sessions
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_assistant_sessions (
    id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED  NULL,
    title       VARCHAR(255)  NOT NULL DEFAULT 'New Conversation',
    created_at  DATETIME      NOT NULL,
    updated_at  DATETIME      NOT NULL,
    CONSTRAINT fk_aas_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Admin Copilot — Per-turn messages
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_assistant_messages (
    id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    session_id  INT UNSIGNED  NOT NULL,
    role        ENUM('user','assistant') NOT NULL,
    content     MEDIUMTEXT    NOT NULL,
    created_at  DATETIME      NOT NULL,
    CONSTRAINT fk_aam_session FOREIGN KEY (session_id) REFERENCES ai_assistant_sessions(id) ON DELETE CASCADE,
    INDEX idx_aam_session_created (session_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    INDEX idx_sae_event_created   (event_type, created_at),
    INDEX idx_sae_episode_created (episode_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Site Analytics Daily  (aggregated per-episode rollups)
-- Populated by rollup_daily_analytics().
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
    INDEX idx_sms_queue_snapped    (queue_id, snapped_at),
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

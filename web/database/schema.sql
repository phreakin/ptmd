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
-- cases
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cases (
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
-- case Categories
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS case_categories (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL UNIQUE,
    slug       VARCHAR(140) NOT NULL UNIQUE,
    created_at DATETIME     NOT NULL,
    updated_at DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- case Tags
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS case_tags (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL UNIQUE,
    slug       VARCHAR(140) NOT NULL UNIQUE,
    created_at DATETIME     NOT NULL,
    updated_at DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS case_tag_map (
    case_id INT UNSIGNED NOT NULL,
    tag_id     INT UNSIGNED NOT NULL,
    PRIMARY KEY (case_id, tag_id),
    CONSTRAINT fk_etm_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    CONSTRAINT fk_etm_tag     FOREIGN KEY (tag_id)     REFERENCES case_tags(id) ON DELETE CASCADE
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
    case_id       INT UNSIGNED   NULL,
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
    CONSTRAINT fk_spq_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
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
-- Chat Users  (public-facing accounts, separate from admin users)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_users (
    id               INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    username         VARCHAR(50)   NOT NULL UNIQUE,
    email            VARCHAR(150)  NULL UNIQUE,
    password_hash    VARCHAR(255)  NULL,
    display_name     VARCHAR(80)   NOT NULL,
    avatar_color     VARCHAR(7)    NOT NULL DEFAULT '#2EC4B6',
    role             ENUM('guest','registered','moderator','admin','super_admin') NOT NULL DEFAULT 'registered',
    status           ENUM('active','muted','banned') NOT NULL DEFAULT 'active',
    muted_until      DATETIME      NULL,
    badge_label      VARCHAR(50)   NULL,
    remember_token   VARCHAR(64)   NULL,
    last_message_at  DATETIME      NULL,
    created_at       DATETIME      NOT NULL,
    updated_at       DATETIME      NOT NULL,
    INDEX idx_cu_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Chat Rooms
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_rooms (
    id                  INT UNSIGNED       AUTO_INCREMENT PRIMARY KEY,
    slug                VARCHAR(120)       NOT NULL UNIQUE,
    name                VARCHAR(255)       NOT NULL,
    description         TEXT               NULL,
    case_id             INT UNSIGNED       NULL,   -- optional link to a specific case
    is_live             TINYINT(1)         NOT NULL DEFAULT 0,
    slow_mode_seconds   SMALLINT UNSIGNED  NOT NULL DEFAULT 0,
    members_only        TINYINT(1)         NOT NULL DEFAULT 0,
    is_archived         TINYINT(1)         NOT NULL DEFAULT 0,
    created_at          DATETIME           NOT NULL,
    updated_at          DATETIME           NOT NULL,
    CONSTRAINT fk_cr_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Chat Messages
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_messages (
    id               INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    username         VARCHAR(50)   NOT NULL,
    message          TEXT          NOT NULL,
    status           ENUM('approved','flagged','blocked') NOT NULL DEFAULT 'approved',
    emojis_json      JSON          NULL,
    ip_hash          VARCHAR(64)   NULL,   -- hashed IP for moderation (not raw)
    chat_user_id     INT UNSIGNED  NULL,   -- linked registered chat user (NULL = anonymous)
    room_id          INT UNSIGNED  NULL,   -- room this message belongs to
    parent_id        INT UNSIGNED  NULL,   -- reply threading
    is_pinned        TINYINT(1)    NOT NULL DEFAULT 0,
    is_highlighted   TINYINT(1)    NOT NULL DEFAULT 0,  -- Super Chat equivalent
    highlight_color  VARCHAR(7)    NULL,
    highlight_amount DECIMAL(8,2)  NULL,
    deleted_at       DATETIME      NULL,   -- soft delete
    deleted_by       INT UNSIGNED  NULL,   -- chat_user_id who deleted
    created_at       DATETIME      NOT NULL,
    updated_at       DATETIME      NOT NULL,
    CONSTRAINT fk_cm_user       FOREIGN KEY (chat_user_id) REFERENCES chat_users(id)    ON DELETE SET NULL,
    CONSTRAINT fk_cm_room       FOREIGN KEY (room_id)      REFERENCES chat_rooms(id)    ON DELETE SET NULL,
    CONSTRAINT fk_cm_parent     FOREIGN KEY (parent_id)    REFERENCES chat_messages(id) ON DELETE SET NULL,
    CONSTRAINT fk_cm_deleted_by FOREIGN KEY (deleted_by)   REFERENCES chat_users(id)    ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_created (created_at),
    INDEX idx_room_created (room_id, created_at),
    INDEX idx_pinned (is_pinned)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Chat Moderation Logs
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_moderation_logs (
    id               INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    chat_message_id  INT UNSIGNED  NULL,   -- NULL for user-level actions (mute/ban)
    moderator_id     INT UNSIGNED  NULL,   -- admin users.id (NULL for chat-role moderators)
    target_user_id   INT UNSIGNED  NULL,   -- chat_users.id who was acted on
    action           VARCHAR(50)   NOT NULL,   -- approved | flagged | blocked | deleted | pinned | muted_user | banned_user | unbanned_user
    reason           VARCHAR(255)  NULL,
    created_at       DATETIME      NOT NULL,
    CONSTRAINT fk_cml_message     FOREIGN KEY (chat_message_id) REFERENCES chat_messages(id) ON DELETE SET NULL,
    CONSTRAINT fk_cml_user        FOREIGN KEY (moderator_id)    REFERENCES users(id)          ON DELETE SET NULL,
    CONSTRAINT fk_cml_target_user FOREIGN KEY (target_user_id)  REFERENCES chat_users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Chat Reactions  (per-user emoji reactions on messages)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_reactions (
    id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    message_id    INT UNSIGNED  NOT NULL,
    chat_user_id  INT UNSIGNED  NOT NULL,
    reaction      VARCHAR(10)   NOT NULL,
    created_at    DATETIME      NOT NULL,
    CONSTRAINT fk_creact_message FOREIGN KEY (message_id)   REFERENCES chat_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_creact_user    FOREIGN KEY (chat_user_id) REFERENCES chat_users(id)    ON DELETE CASCADE,
    UNIQUE KEY uq_reaction (message_id, chat_user_id, reaction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Chat User Bans  (room-scoped or global bans)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_user_bans (
    id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    chat_user_id  INT UNSIGNED  NOT NULL,
    room_id       INT UNSIGNED  NULL,    -- NULL = global ban
    banned_by     INT UNSIGNED  NOT NULL,
    reason        TEXT          NULL,
    expires_at    DATETIME      NULL,    -- NULL = permanent
    created_at    DATETIME      NOT NULL,
    CONSTRAINT fk_cub_user      FOREIGN KEY (chat_user_id) REFERENCES chat_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_cub_room      FOREIGN KEY (room_id)      REFERENCES chat_rooms(id) ON DELETE SET NULL,
    CONSTRAINT fk_cub_banned_by FOREIGN KEY (banned_by)    REFERENCES chat_users(id) ON DELETE CASCADE,
    INDEX idx_cub_user_room (chat_user_id, room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- AI Generations  (log every OpenAI call + output)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_generations (
    id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    case_id      INT UNSIGNED  NULL,
    feature         VARCHAR(80)   NOT NULL,   -- video_ideas | title | keywords | description | caption | thumbnail_concept | case_field_suggestion
    input_prompt    TEXT          NOT NULL,
    output_text     MEDIUMTEXT    NOT NULL,
    model           VARCHAR(80)   NOT NULL,
    prompt_tokens   INT UNSIGNED  NOT NULL DEFAULT 0,
    response_tokens INT UNSIGNED  NOT NULL DEFAULT 0,
    created_at      DATETIME      NOT NULL,
    CONSTRAINT fk_ag_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
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
-- Video Clips  (short-form clips extracted from cases)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS video_clips (
    id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    case_id      INT UNSIGNED  NULL,
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
    CONSTRAINT fk_vc_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Posting Sites  (canonical list of social media posting targets)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS posting_sites (
    id           INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
    site_key     VARCHAR(80)       NOT NULL UNIQUE,   -- stable slug: youtube, youtube_shorts, tiktok, etc.
    display_name VARCHAR(120)      NOT NULL,          -- human-readable: YouTube, YouTube Shorts, etc.
    is_active    TINYINT(1)        NOT NULL DEFAULT 1,
    sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at   DATETIME          NOT NULL,
    updated_at   DATETIME          NOT NULL,
    INDEX idx_ps_active_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Site Posting Options  (per-site defaults for content + caption)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_posting_options (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id                INT UNSIGNED NOT NULL UNIQUE,
    default_content_type   VARCHAR(80)  NULL,
    default_caption_prefix TEXT         NULL,
    default_hashtags       VARCHAR(255) NULL,
    default_status         ENUM('draft','queued','scheduled') NOT NULL DEFAULT 'queued',
    created_at             DATETIME     NOT NULL,
    updated_at             DATETIME     NOT NULL,
    CONSTRAINT fk_spo_site FOREIGN KEY (site_id) REFERENCES posting_sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Content Workflow Runs (topic -> asset -> posting lifecycle)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS content_workflows (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    topic             VARCHAR(255)  NOT NULL,
    case_id           INT UNSIGNED  NULL,
    source_clip_id    INT UNSIGNED  NULL,
    source_asset_path VARCHAR(255)  NULL,
    status            ENUM('draft','planned','queued','posting','completed','failed') NOT NULL DEFAULT 'planned',
    notes             TEXT          NULL,
    created_by        INT UNSIGNED  NULL,
    created_at        DATETIME      NOT NULL,
    updated_at        DATETIME      NOT NULL,
    CONSTRAINT fk_cw_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
    CONSTRAINT fk_cw_clip FOREIGN KEY (source_clip_id) REFERENCES video_clips(id) ON DELETE SET NULL,
    CONSTRAINT fk_cw_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_cw_status_created (status, created_at),
    INDEX idx_cw_case (case_id),
    INDEX idx_cw_clip (source_clip_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Content Workflow Asset Assignments
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS content_workflow_assets (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workflow_id   INT UNSIGNED  NOT NULL,
    asset_role    ENUM('primary_video','clip_video','thumbnail','overlay','other') NOT NULL DEFAULT 'other',
    asset_path    VARCHAR(255)  NOT NULL,
    clip_id       INT UNSIGNED  NULL,
    site_id       INT UNSIGNED  NULL,
    assigned_at   DATETIME      NOT NULL,
    created_at    DATETIME      NOT NULL,
    updated_at    DATETIME      NOT NULL,
    CONSTRAINT fk_cwa_workflow FOREIGN KEY (workflow_id) REFERENCES content_workflows(id) ON DELETE CASCADE,
    CONSTRAINT fk_cwa_clip FOREIGN KEY (clip_id) REFERENCES video_clips(id) ON DELETE SET NULL,
    CONSTRAINT fk_cwa_site FOREIGN KEY (site_id) REFERENCES posting_sites(id) ON DELETE SET NULL,
    INDEX idx_cwa_workflow_role (workflow_id, asset_role),
    INDEX idx_cwa_site_role (site_id, asset_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Content Workflow Posting Tasks
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS content_workflow_posts (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workflow_id           INT UNSIGNED  NOT NULL,
    site_id               INT UNSIGNED  NOT NULL,
    queue_id              INT UNSIGNED  NULL,
    platform_display_name VARCHAR(80)   NOT NULL,
    content_type          VARCHAR(80)   NOT NULL,
    caption               TEXT          NULL,
    scheduled_for         DATETIME      NOT NULL,
    status                ENUM('planned','queued','posted','failed','canceled') NOT NULL DEFAULT 'planned',
    last_error            TEXT          NULL,
    created_at            DATETIME      NOT NULL,
    updated_at            DATETIME      NOT NULL,
    CONSTRAINT fk_cwp_workflow FOREIGN KEY (workflow_id) REFERENCES content_workflows(id) ON DELETE CASCADE,
    CONSTRAINT fk_cwp_site FOREIGN KEY (site_id) REFERENCES posting_sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_cwp_queue FOREIGN KEY (queue_id) REFERENCES social_post_queue(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_cwp_workflow_site (workflow_id, site_id),
    INDEX idx_cwp_status_schedule (status, scheduled_for),
    INDEX idx_cwp_queue (queue_id)
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
-- Edit Jobs  (first-class automation job: source → renders → publish)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS edit_jobs (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    label           VARCHAR(255)    NOT NULL DEFAULT 'Untitled Edit Job',
    source_clip_id  INT UNSIGNED    NULL,              -- FK to video_clips (nullable for path-only jobs)
    source_path     VARCHAR(255)    NOT NULL,           -- relative to /uploads
    caption_mode    ENUM('none','embedded','sidecar') NOT NULL DEFAULT 'none',
    platforms_json  JSON            NULL,               -- array of platform target strings
    status          ENUM('pending','processing','completed','failed','canceled') NOT NULL DEFAULT 'pending',
    retry_count     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_retries     TINYINT UNSIGNED NOT NULL DEFAULT 3,
    error_message   TEXT            NULL,
    created_by      INT UNSIGNED    NULL,
    created_at      DATETIME        NOT NULL,
    updated_at      DATETIME        NOT NULL,
    CONSTRAINT fk_ej_clip FOREIGN KEY (source_clip_id) REFERENCES video_clips(id) ON DELETE SET NULL,
    CONSTRAINT fk_ej_user FOREIGN KEY (created_by)     REFERENCES users(id)       ON DELETE SET NULL,
    INDEX idx_ej_status (status),
    INDEX idx_ej_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Edit Job Outputs  (one row per platform variant / render target)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS edit_job_outputs (
    id                 INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    job_id             INT UNSIGNED    NOT NULL,
    platform           VARCHAR(80)     NOT NULL DEFAULT 'generic',
    caption_mode       ENUM('none','embedded','sidecar') NOT NULL DEFAULT 'none',
    overlay_path       VARCHAR(255)    NULL,            -- primary overlay image relative to /web root
    image_layers_json  JSON            NULL,            -- [{path,position,scale,opacity,start_sec,end_sec}, …]
    render_config_json JSON            NULL,            -- extra ffmpeg / render options
    output_path        VARCHAR(255)    NULL,            -- relative to /uploads
    status             ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
    error_message      TEXT            NULL,
    ffmpeg_command     TEXT            NULL,            -- stored for debugging / replay
    retry_count        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    queue_item_id      INT UNSIGNED    NULL,            -- FK to social_post_queue when queued for publish
    created_at         DATETIME        NOT NULL,
    updated_at         DATETIME        NOT NULL,
    CONSTRAINT fk_ejo_job FOREIGN KEY (job_id) REFERENCES edit_jobs(id) ON DELETE CASCADE,
    INDEX idx_ejo_job_status (job_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Clip Captions  (caption text / sidecar files linked to clips or jobs)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clip_captions (
    id                INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    clip_id           INT UNSIGNED    NULL,
    job_id            INT UNSIGNED    NULL,
    caption_mode      ENUM('embedded','sidecar') NOT NULL DEFAULT 'embedded',
    caption_text      TEXT            NULL,             -- plain text caption / SRT source
    srt_path          VARCHAR(255)    NULL,             -- relative to /uploads
    vtt_path          VARCHAR(255)    NULL,             -- relative to /uploads
    ai_generation_id  INT UNSIGNED    NULL,
    created_at        DATETIME        NOT NULL,
    updated_at        DATETIME        NOT NULL,
    CONSTRAINT fk_cc_clip FOREIGN KEY (clip_id)          REFERENCES video_clips(id)    ON DELETE SET NULL,
    CONSTRAINT fk_cc_job  FOREIGN KEY (job_id)           REFERENCES edit_jobs(id)      ON DELETE SET NULL,
    CONSTRAINT fk_cc_ai   FOREIGN KEY (ai_generation_id) REFERENCES ai_generations(id) ON DELETE SET NULL,
    INDEX idx_cc_clip (clip_id),
    INDEX idx_cc_job  (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Text & Media Assets  (reusable content blocks for automation)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS assets (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_type        ENUM(
                        'hook', 'one_liner', 'script', 'subtitle',
                        'image', 'overlay', 'thumbnail', 'video_clip',
                        'audio', 'template', 'other'
                      ) NOT NULL,
    title             VARCHAR(255)  NULL,
    slug              VARCHAR(255)  NULL UNIQUE,
    content_text      MEDIUMTEXT    NULL,   -- hooks, scripts, captions, etc.
    content_json      JSON          NULL,   -- structured formats (LRC, SRT, configs)
    source_notes      TEXT          NULL,
    file_path         VARCHAR(255)  NULL,   -- for image / overlay / clip assets
    tone              VARCHAR(100)  NULL,   -- dark, funny, investigative, sarcastic
    category          VARCHAR(100)  NULL,   -- intro, hook, outro, transition, etc.
    topic             VARCHAR(120)  NULL,
    target_phase      ENUM('hook','setup','payoff','loop','caption','overlay','subtitle','full_script') NULL,
    tags_json         JSON          NULL,
    usage_count       INT UNSIGNED  NOT NULL DEFAULT 0,
    last_used_at      DATETIME      NULL,
    performance_score DECIMAL(5,2)  NULL,
    engagement_score  DECIMAL(5,2)  NULL,
    status            ENUM('draft','active','archived') NOT NULL DEFAULT 'active',
    is_favorite       TINYINT(1)    NOT NULL DEFAULT 0,
    approved          TINYINT(1)    NOT NULL DEFAULT 1,
    created_by        INT UNSIGNED  NULL,
    created_at        DATETIME      NOT NULL,
    updated_at        DATETIME      NOT NULL,
    INDEX idx_asset_type (asset_type),
    INDEX idx_asset_status (status),
    INDEX idx_asset_tone (tone),
    INDEX idx_asset_performance (performance_score),
    FULLTEXT INDEX idx_asset_content (content_text),
    CONSTRAINT fk_assets_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Asset Usage Logs  (per-post performance tracking)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS asset_usage_logs (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id              INT UNSIGNED  NOT NULL,
    case_id               INT UNSIGNED  NULL,
    clip_id               INT UNSIGNED  NULL,
    platform              VARCHAR(80)   NULL,
    topic                 VARCHAR(120)  NULL,
    used_as               ENUM('hook','setup','payoff','loop','caption','overlay','subtitle','script') NOT NULL,
    views                 INT UNSIGNED  NOT NULL DEFAULT 0,
    likes                 INT UNSIGNED  NOT NULL DEFAULT 0,
    comments_count        INT UNSIGNED  NOT NULL DEFAULT 0,
    shares                INT UNSIGNED  NOT NULL DEFAULT 0,
    watch_time_sec        DECIMAL(10,2) NULL,
    avg_view_duration_sec DECIMAL(10,2) NULL,
    completion_rate       DECIMAL(5,2)  NULL,
    ctr                   DECIMAL(5,2)  NULL,
    engagement_score      DECIMAL(8,2)  NULL,
    performance_score     DECIMAL(8,2)  NULL,
    created_at            DATETIME      NOT NULL,
    updated_at            DATETIME      NOT NULL,
    CONSTRAINT fk_aul_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_aul_case  FOREIGN KEY (case_id)  REFERENCES cases(id)        ON DELETE SET NULL,
    CONSTRAINT fk_aul_clip  FOREIGN KEY (clip_id)  REFERENCES video_clips(id)  ON DELETE SET NULL,
    INDEX idx_aul_asset_topic    (asset_id, topic),
    INDEX idx_aul_topic_usedas   (topic, used_as),
    INDEX idx_aul_platform_usedas (platform, used_as),
    INDEX idx_aul_perf           (performance_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Clip Blueprints  (asset-assembly production spec for social clips)
-- ------------------------------------------------------------
-- Links specific asset rows (hook/setup/payoff/etc.) to produce a clip.
-- For reusable clip format templates see clip_format_templates below.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clip_blueprints (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id           INT UNSIGNED  NULL,
    platform          VARCHAR(80)   NOT NULL,
    topic             VARCHAR(120)  NULL,
    title             VARCHAR(255)  NULL,
    hook_asset_id     INT UNSIGNED  NULL,
    setup_asset_id    INT UNSIGNED  NULL,
    payoff_asset_id   INT UNSIGNED  NULL,
    loop_asset_id     INT UNSIGNED  NULL,
    overlay_asset_id  INT UNSIGNED  NULL,
    subtitle_asset_id INT UNSIGNED  NULL,
    caption_asset_id  INT UNSIGNED  NULL,
    source_video_path VARCHAR(255)  NULL,
    output_video_path VARCHAR(255)  NULL,
    blueprint_json    JSON          NULL,
    status            ENUM('draft','ready','rendering','rendered','failed') NOT NULL DEFAULT 'draft',
    created_at        DATETIME      NOT NULL,
    updated_at        DATETIME      NOT NULL,
    CONSTRAINT fk_cb_case     FOREIGN KEY (case_id)           REFERENCES cases(id)  ON DELETE SET NULL,
    CONSTRAINT fk_cb_hook     FOREIGN KEY (hook_asset_id)     REFERENCES assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_cb_setup    FOREIGN KEY (setup_asset_id)    REFERENCES assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_cb_payoff   FOREIGN KEY (payoff_asset_id)   REFERENCES assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_cb_loop     FOREIGN KEY (loop_asset_id)     REFERENCES assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_cb_overlay  FOREIGN KEY (overlay_asset_id)  REFERENCES assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_cb_subtitle FOREIGN KEY (subtitle_asset_id) REFERENCES assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_cb_caption  FOREIGN KEY (caption_asset_id)  REFERENCES assets(id) ON DELETE SET NULL,
    INDEX idx_cb_case_platform (case_id, platform),
    INDEX idx_cb_status        (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Blueprint Layer  (Phase 1 — reusable content templates)
-- ============================================================

-- ------------------------------------------------------------
-- Video Blueprints  (templates for long-form / primary video)
-- ------------------------------------------------------------
-- Each blueprint defines the intended structure, tone, and goals for
-- a full case video (documentary, teaser cut, reaction, follow-up, etc.).
-- Generate a video_instance from one of these when starting work on a case.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS video_blueprints (
    id                  INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    title               VARCHAR(255)  NOT NULL,
    slug                VARCHAR(255)  NOT NULL UNIQUE,
    blueprint_type      ENUM('documentary','teaser','reaction','follow_up','custom') NOT NULL DEFAULT 'documentary',
    status              ENUM('active','draft','archived') NOT NULL DEFAULT 'draft',
    objective           TEXT          NULL,             -- e.g. "Drive subscribers, establish case authority"
    structure_json      JSON          NULL,             -- ordered sections/segments with labels + notes
    brand_notes         TEXT          NULL,             -- voice, tone, required brand treatment
    target_duration_sec INT UNSIGNED  NULL,             -- target length in seconds (e.g. 1200 = 20 min)
    created_by          INT UNSIGNED  NULL,
    created_at          DATETIME      NOT NULL,
    updated_at          DATETIME      NOT NULL,
    CONSTRAINT fk_vb_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_vb_status      (status),
    INDEX idx_vb_type_status (blueprint_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Clip Format Templates  (reusable format templates for short-form clips)
-- ------------------------------------------------------------
-- Each template defines a clip format: hook structure, target duration,
-- aspect ratio, and which platforms it is intended for.
-- Generate a clip_instance from one of these when cutting clips from a case.
-- (Named clip_format_templates to avoid conflict with clip_blueprints above.)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clip_format_templates (
    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    title               VARCHAR(255)    NOT NULL,
    slug                VARCHAR(255)    NOT NULL UNIQUE,
    clip_type           ENUM('teaser','reveal','punch','humor','follow_up','custom') NOT NULL DEFAULT 'teaser',
    status              ENUM('active','draft','archived') NOT NULL DEFAULT 'draft',
    target_duration_sec SMALLINT UNSIGNED NULL,         -- e.g. 30, 45, 60
    aspect_ratio        VARCHAR(20)     NULL,            -- "9:16" for vertical, "16:9" for landscape
    platform_targets    JSON            NULL,            -- array of posting_sites.site_key values
    structure_json      JSON            NULL,            -- hook / body / CTA structure
    brand_notes         TEXT            NULL,
    created_by          INT UNSIGNED    NULL,
    created_at          DATETIME        NOT NULL,
    updated_at          DATETIME        NOT NULL,
    CONSTRAINT fk_cft_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_cft_status      (status),
    INDEX idx_cft_type_status (clip_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Posting Blueprints  (templates for platform-specific post jobs)
-- ------------------------------------------------------------
-- Each blueprint defines how a piece of content should be posted to ONE
-- platform: caption template, required hashtags, CTA pattern, brand rules.
-- One posting_blueprint per platform variant (e.g. TikTok teaser, YT Shorts).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS posting_blueprints (
    id                  INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    title               VARCHAR(255)  NOT NULL,
    slug                VARCHAR(255)  NOT NULL UNIQUE,
    site_key            VARCHAR(80)   NOT NULL,          -- FK to posting_sites.site_key
    content_type        VARCHAR(80)   NOT NULL,          -- teaser, full_documentary, launch_thread, etc.
    status              ENUM('active','draft','archived') NOT NULL DEFAULT 'draft',
    caption_template    TEXT          NULL,              -- supports {title}, {hashtags}, {cta} tokens
    required_hashtags   VARCHAR(500)  NULL,
    banned_phrases      TEXT          NULL,              -- comma-separated phrases to reject in captions
    cta_pattern         VARCHAR(255)  NULL,              -- e.g. "Subscribe + link in bio"
    config_json         JSON          NULL,              -- extra platform-specific rules (char limit, etc.)
    created_by          INT UNSIGNED  NULL,
    created_at          DATETIME      NOT NULL,
    updated_at          DATETIME      NOT NULL,
    CONSTRAINT fk_pb_site FOREIGN KEY (site_key)    REFERENCES posting_sites(site_key) ON DELETE CASCADE,
    CONSTRAINT fk_pb_user FOREIGN KEY (created_by)  REFERENCES users(id)               ON DELETE SET NULL,
    INDEX idx_pb_site_key    (site_key),
    INDEX idx_pb_status      (status),
    INDEX idx_pb_site_status (site_key, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Blueprint Schedule Rules  (per-blueprint cadence + priority)
-- ------------------------------------------------------------
-- Extends the flat social_post_schedules table with blueprint-level rules:
-- priority ordering, minimum gap between posts, and max-per-day guard.
-- Used by the auto-scheduler to find the next valid slot for a post_job.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS blueprint_schedule_rules (
    id                   INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    posting_blueprint_id INT UNSIGNED  NOT NULL,
    site_key             VARCHAR(80)   NOT NULL,         -- redundant with blueprint but useful for direct queries
    day_of_week          ENUM('Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday') NOT NULL,
    post_time            TIME          NOT NULL,
    timezone             VARCHAR(100)  NOT NULL DEFAULT 'America/Phoenix',
    priority             TINYINT UNSIGNED NOT NULL DEFAULT 5,   -- 1 = highest, 10 = lowest
    min_gap_hours        TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- min hours between posts to this platform
    max_per_day          TINYINT UNSIGNED NOT NULL DEFAULT 1,   -- max posts per day to this platform
    is_active            TINYINT(1)    NOT NULL DEFAULT 1,
    created_at           DATETIME      NOT NULL,
    updated_at           DATETIME      NOT NULL,
    CONSTRAINT fk_bsr_blueprint FOREIGN KEY (posting_blueprint_id) REFERENCES posting_blueprints(id) ON DELETE CASCADE,
    CONSTRAINT fk_bsr_site      FOREIGN KEY (site_key)             REFERENCES posting_sites(site_key) ON DELETE CASCADE,
    INDEX idx_bsr_active      (posting_blueprint_id, is_active),
    INDEX idx_bsr_site_day    (site_key, day_of_week),
    INDEX idx_bsr_priority    (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Instance Layer  (generated content from blueprints)
-- ============================================================

-- ------------------------------------------------------------
-- Video Instances  (a specific video plan generated from a blueprint)
-- ------------------------------------------------------------
-- Created when an admin selects a video_blueprint for a given case.
-- Tracks the lifecycle of that video from draft to posted.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS video_instances (
    id                  INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    video_blueprint_id  INT UNSIGNED  NOT NULL,
    case_id             INT UNSIGNED  NULL,
    title               VARCHAR(255)  NOT NULL,
    status              ENUM('draft','approved','queued','scheduled','posted','failed','canceled') NOT NULL DEFAULT 'draft',
    blueprint_version   SMALLINT UNSIGNED NOT NULL DEFAULT 1,  -- snapshot of blueprint version used
    notes               TEXT          NULL,
    created_by          INT UNSIGNED  NULL,
    created_at          DATETIME      NOT NULL,
    updated_at          DATETIME      NOT NULL,
    CONSTRAINT fk_vi_blueprint FOREIGN KEY (video_blueprint_id) REFERENCES video_blueprints(id) ON DELETE RESTRICT,
    CONSTRAINT fk_vi_case      FOREIGN KEY (case_id)            REFERENCES cases(id)            ON DELETE SET NULL,
    CONSTRAINT fk_vi_user      FOREIGN KEY (created_by)         REFERENCES users(id)            ON DELETE SET NULL,
    INDEX idx_vi_blueprint (video_blueprint_id),
    INDEX idx_vi_case      (case_id),
    INDEX idx_vi_status    (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Clip Instances  (a specific short clip generated from a format template)
-- ------------------------------------------------------------
-- Created when an admin selects a clip_format_template for a case or video_instance.
-- May reference an existing video_clips row once the clip is cut.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clip_instances (
    id                       INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    clip_format_template_id  INT UNSIGNED  NOT NULL,
    video_instance_id        INT UNSIGNED  NULL,       -- parent video, if applicable
    case_id                  INT UNSIGNED  NULL,
    video_clip_id            INT UNSIGNED  NULL,       -- existing video_clips row (once processed)
    title                    VARCHAR(255)  NOT NULL,
    status                   ENUM('draft','approved','queued','scheduled','posted','failed','canceled') NOT NULL DEFAULT 'draft',
    blueprint_version        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    notes                    TEXT          NULL,
    created_by               INT UNSIGNED  NULL,
    created_at               DATETIME      NOT NULL,
    updated_at               DATETIME      NOT NULL,
    CONSTRAINT fk_ci_template       FOREIGN KEY (clip_format_template_id) REFERENCES clip_format_templates(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ci_video_instance FOREIGN KEY (video_instance_id)       REFERENCES video_instances(id)       ON DELETE SET NULL,
    CONSTRAINT fk_ci_case           FOREIGN KEY (case_id)                 REFERENCES cases(id)                 ON DELETE SET NULL,
    CONSTRAINT fk_ci_video_clip     FOREIGN KEY (video_clip_id)           REFERENCES video_clips(id)           ON DELETE SET NULL,
    CONSTRAINT fk_ci_user           FOREIGN KEY (created_by)              REFERENCES users(id)                 ON DELETE SET NULL,
    INDEX idx_ci_template       (clip_format_template_id),
    INDEX idx_ci_video_instance (video_instance_id),
    INDEX idx_ci_status         (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Post Jobs  (a post task generated from a posting blueprint)
-- ------------------------------------------------------------
-- One row per "content × platform" combination to be published.
-- When the scheduler finds a valid slot it creates a social_post_queue row
-- and stores its id in queue_id.  lock_key prevents duplicate queue inserts.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS post_jobs (
    id                   INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    posting_blueprint_id INT UNSIGNED  NOT NULL,
    clip_instance_id     INT UNSIGNED  NULL,
    video_instance_id    INT UNSIGNED  NULL,
    queue_id             INT UNSIGNED  NULL,        -- social_post_queue.id once enqueued
    status               ENUM('draft','approved','queued','scheduled','posted','failed','canceled') NOT NULL DEFAULT 'draft',
    scheduled_for        DATETIME      NULL,
    conflict_reason      TEXT          NULL,         -- why scheduling was blocked
    lock_key             VARCHAR(255)  NULL,         -- idempotency key (blueprint+instance hash)
    created_by           INT UNSIGNED  NULL,
    created_at           DATETIME      NOT NULL,
    updated_at           DATETIME      NOT NULL,
    CONSTRAINT fk_pj_blueprint      FOREIGN KEY (posting_blueprint_id) REFERENCES posting_blueprints(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pj_clip_instance  FOREIGN KEY (clip_instance_id)     REFERENCES clip_instances(id)     ON DELETE SET NULL,
    CONSTRAINT fk_pj_video_instance FOREIGN KEY (video_instance_id)    REFERENCES video_instances(id)    ON DELETE SET NULL,
    CONSTRAINT fk_pj_queue          FOREIGN KEY (queue_id)             REFERENCES social_post_queue(id)  ON DELETE SET NULL,
    CONSTRAINT fk_pj_user           FOREIGN KEY (created_by)           REFERENCES users(id)              ON DELETE SET NULL,
    UNIQUE KEY  uq_pj_lock_key      (lock_key),
    INDEX idx_pj_blueprint  (posting_blueprint_id),
    INDEX idx_pj_status     (status),
    INDEX idx_pj_scheduled  (scheduled_for)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Content Status Log  (audit trail for all status transitions)
-- ------------------------------------------------------------
-- Records every status change on video_instances, clip_instances, and
-- post_jobs.  Gives admins full visibility and supports recovery.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS content_status_log (
    id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    entity_type  ENUM('video_instance','clip_instance','post_job') NOT NULL,
    entity_id    INT UNSIGNED  NOT NULL,
    from_status  VARCHAR(50)   NULL,
    to_status    VARCHAR(50)   NOT NULL,
    changed_by   INT UNSIGNED  NULL,    -- users.id (NULL = system/cron)
    reason       TEXT          NULL,
    created_at   DATETIME      NOT NULL,
    INDEX idx_csl_entity  (entity_type, entity_id),
    INDEX idx_csl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Viewer Users  (public-facing accounts, separate from admin users)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS viewer_users (
    id                 INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    username           VARCHAR(50)   NOT NULL UNIQUE,
    email              VARCHAR(150)  NOT NULL UNIQUE,
    password_hash      VARCHAR(255)  NOT NULL,
    display_name       VARCHAR(100)  NULL,
    avatar_url         VARCHAR(255)  NULL,
    status             ENUM('active','suspended') NOT NULL DEFAULT 'active',
    email_verified_at  DATETIME      NULL,
    created_at         DATETIME      NOT NULL,
    updated_at         DATETIME      NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Viewer Sessions  (token-based sessions for viewer_users)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS viewer_sessions (
    id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    viewer_id     INT UNSIGNED  NOT NULL,
    session_token VARCHAR(64)   NOT NULL UNIQUE,
    expires_at    DATETIME      NOT NULL,
    ip_hash       VARCHAR(64)   NULL,
    created_at    DATETIME      NOT NULL,
    CONSTRAINT fk_vs_viewer FOREIGN KEY (viewer_id) REFERENCES viewer_users(id) ON DELETE CASCADE,
    INDEX idx_vs_token (session_token),
    INDEX idx_vs_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Episode Favorites  (per-viewer saved episodes)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS episode_favorites (
    id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    viewer_id   INT UNSIGNED  NOT NULL,
    episode_id  INT UNSIGNED  NOT NULL,
    created_at  DATETIME      NOT NULL,
    UNIQUE KEY uq_viewer_episode (viewer_id, episode_id),
    CONSTRAINT fk_ef_viewer  FOREIGN KEY (viewer_id)  REFERENCES viewer_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ef_episode FOREIGN KEY (episode_id) REFERENCES episodes(id)     ON DELETE CASCADE
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

-- ============================================================
-- NOTE: After schema.sql, run seed.sql for default data.
-- ============================================================

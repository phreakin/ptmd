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
    episode_id          INT UNSIGNED       NULL,
    is_live             TINYINT(1)         NOT NULL DEFAULT 0,
    slow_mode_seconds   SMALLINT UNSIGNED  NOT NULL DEFAULT 0,
    members_only        TINYINT(1)         NOT NULL DEFAULT 0,
    is_archived         TINYINT(1)         NOT NULL DEFAULT 0,
    created_at          DATETIME           NOT NULL,
    updated_at          DATETIME           NOT NULL,
    CONSTRAINT fk_cr_episode FOREIGN KEY (episode_id) REFERENCES episodes(id) ON DELETE SET NULL
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

SET FOREIGN_KEY_CHECKS = 1;

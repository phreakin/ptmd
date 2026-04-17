-- ============================================================
-- PTMD Schema Migration v2 — Netflix-Level Upgrade
-- Run AFTER schema.sql is already applied.
-- Safe to run multiple times (uses IF NOT EXISTS / ADD COLUMN IF NOT EXISTS via procedure).
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. Add intro/outro columns to episodes
-- ------------------------------------------------------------
ALTER TABLE episodes
    ADD COLUMN IF NOT EXISTS intro_asset_path VARCHAR(255) NULL AFTER video_file_path,
    ADD COLUMN IF NOT EXISTS outro_asset_path VARCHAR(255) NULL AFTER intro_asset_path;

-- ------------------------------------------------------------
-- 2. Timeline Overlay Triggers  (per-episode time-synced overlays)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS episode_overlay_triggers (
    id              INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
    episode_id      INT UNSIGNED   NOT NULL,
    label           VARCHAR(255)   NOT NULL DEFAULT '',
    timestamp_in    DECIMAL(10,3)  NOT NULL DEFAULT 0.000,  -- seconds from video start
    timestamp_out   DECIMAL(10,3)  NOT NULL DEFAULT 0.000,  -- seconds from video start
    overlay_path    VARCHAR(255)   NOT NULL,                 -- web-root-relative path
    position        VARCHAR(30)    NOT NULL DEFAULT 'bottom-right',
    opacity         DECIMAL(3,2)   NOT NULL DEFAULT 1.00,
    scale           TINYINT UNSIGNED NOT NULL DEFAULT 30,    -- % of video width
    animation_style VARCHAR(50)    NOT NULL DEFAULT 'none',  -- none | fade | slide-up | slide-down
    sort_order      SMALLINT       NOT NULL DEFAULT 0,
    created_at      DATETIME       NOT NULL,
    updated_at      DATETIME       NOT NULL,
    CONSTRAINT fk_eot_episode FOREIGN KEY (episode_id) REFERENCES episodes(id) ON DELETE CASCADE,
    INDEX idx_eot_episode (episode_id),
    INDEX idx_eot_order   (episode_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. Export Profiles  (platform-specific render presets)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS export_profiles (
    id                 INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    label              VARCHAR(255)  NOT NULL,
    platform_target    VARCHAR(80)   NOT NULL,   -- youtube | youtube_shorts | tiktok | instagram_reels | obs_source
    width              SMALLINT UNSIGNED NOT NULL DEFAULT 1920,
    height             SMALLINT UNSIGNED NOT NULL DEFAULT 1080,
    fps                TINYINT UNSIGNED  NOT NULL DEFAULT 30,
    video_bitrate      VARCHAR(20)   NOT NULL DEFAULT '5000k',
    audio_bitrate      VARCHAR(20)   NOT NULL DEFAULT '192k',
    use_intro          TINYINT(1)    NOT NULL DEFAULT 1,
    use_outro          TINYINT(1)    NOT NULL DEFAULT 1,
    use_watermark      TINYINT(1)    NOT NULL DEFAULT 1,
    use_triggers       TINYINT(1)    NOT NULL DEFAULT 1,     -- apply timeline overlay triggers
    extra_ffmpeg_flags TEXT          NULL,                   -- appended verbatim to ffmpeg cmd
    is_default         TINYINT(1)    NOT NULL DEFAULT 0,
    created_at         DATETIME      NOT NULL,
    updated_at         DATETIME      NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. Export Jobs  (tracks render runs per episode+profile)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS export_jobs (
    id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    episode_id      INT UNSIGNED  NOT NULL,
    profile_id      INT UNSIGNED  NOT NULL,
    output_path     VARCHAR(255)  NULL,       -- relative to /uploads
    status          ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    error_message   TEXT          NULL,
    ffmpeg_command  TEXT          NULL,
    started_at      DATETIME      NULL,
    completed_at    DATETIME      NULL,
    created_by      INT UNSIGNED  NULL,
    created_at      DATETIME      NOT NULL,
    updated_at      DATETIME      NOT NULL,
    CONSTRAINT fk_ej_episode FOREIGN KEY (episode_id) REFERENCES episodes(id) ON DELETE CASCADE,
    CONSTRAINT fk_ej_profile FOREIGN KEY (profile_id) REFERENCES export_profiles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ej_user    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ej_episode (episode_id),
    INDEX idx_ej_status  (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

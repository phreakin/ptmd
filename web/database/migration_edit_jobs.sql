-- ============================================================
-- PTMD Migration: Edit Jobs Pipeline
-- Run this file on existing installs that already have schema.sql applied.
-- Safe to re-run (uses CREATE TABLE IF NOT EXISTS).
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Edit Jobs
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS edit_jobs (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    label           VARCHAR(255)    NOT NULL DEFAULT 'Untitled Edit Job',
    source_clip_id  INT UNSIGNED    NULL,
    source_path     VARCHAR(255)    NOT NULL,
    caption_mode    ENUM('none','embedded','sidecar') NOT NULL DEFAULT 'none',
    platforms_json  JSON            NULL,
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
-- Edit Job Outputs
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS edit_job_outputs (
    id                 INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    job_id             INT UNSIGNED    NOT NULL,
    platform           VARCHAR(80)     NOT NULL DEFAULT 'generic',
    caption_mode       ENUM('none','embedded','sidecar') NOT NULL DEFAULT 'none',
    overlay_path       VARCHAR(255)    NULL,
    image_layers_json  JSON            NULL,
    render_config_json JSON            NULL,
    output_path        VARCHAR(255)    NULL,
    status             ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
    error_message      TEXT            NULL,
    ffmpeg_command     TEXT            NULL,
    retry_count        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    queue_item_id      INT UNSIGNED    NULL,
    created_at         DATETIME        NOT NULL,
    updated_at         DATETIME        NOT NULL,
    CONSTRAINT fk_ejo_job FOREIGN KEY (job_id) REFERENCES edit_jobs(id) ON DELETE CASCADE,
    INDEX idx_ejo_job_status (job_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Clip Captions
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clip_captions (
    id                INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    clip_id           INT UNSIGNED    NULL,
    job_id            INT UNSIGNED    NULL,
    caption_mode      ENUM('embedded','sidecar') NOT NULL DEFAULT 'embedded',
    caption_text      TEXT            NULL,
    srt_path          VARCHAR(255)    NULL,
    vtt_path          VARCHAR(255)    NULL,
    ai_generation_id  INT UNSIGNED    NULL,
    created_at        DATETIME        NOT NULL,
    updated_at        DATETIME        NOT NULL,
    CONSTRAINT fk_cc_clip FOREIGN KEY (clip_id)          REFERENCES video_clips(id)    ON DELETE SET NULL,
    CONSTRAINT fk_cc_job  FOREIGN KEY (job_id)           REFERENCES edit_jobs(id)      ON DELETE SET NULL,
    CONSTRAINT fk_cc_ai   FOREIGN KEY (ai_generation_id) REFERENCES ai_generations(id) ON DELETE SET NULL,
    INDEX idx_cc_clip (clip_id),
    INDEX idx_cc_job  (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

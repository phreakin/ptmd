-- ============================================================
-- Migration: assets + asset_usage_logs (upgrade-only)
--
-- Run this ONLY against installs created before these tables
-- were added to schema.sql.  Safe to re-run (IF NOT EXISTS).
--
-- Fresh installs: schema.sql already includes these tables.
-- ============================================================

-- assets table is defined in schema.sql for fresh installs.
-- This CREATE ensures existing installs get the table.
CREATE TABLE IF NOT EXISTS assets (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_type        ENUM(
                        'hook', 'one_liner', 'script', 'subtitle',
                        'image', 'overlay', 'thumbnail', 'video_clip',
                        'audio', 'template', 'other'
                      ) NOT NULL,
    title             VARCHAR(255)  NULL,
    slug              VARCHAR(255)  NULL UNIQUE,
    content_text      MEDIUMTEXT    NULL,
    content_json      JSON          NULL,
    source_notes      TEXT          NULL,
    file_path         VARCHAR(255)  NULL,
    tone              VARCHAR(100)  NULL,
    category          VARCHAR(100)  NULL,
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
    CONSTRAINT fk_aul_case  FOREIGN KEY (case_id)  REFERENCES cases(id)       ON DELETE SET NULL,
    CONSTRAINT fk_aul_clip  FOREIGN KEY (clip_id)  REFERENCES video_clips(id) ON DELETE SET NULL,
    INDEX idx_aul_asset_topic     (asset_id, topic),
    INDEX idx_aul_topic_usedas    (topic, used_as),
    INDEX idx_aul_platform_usedas (platform, used_as),
    INDEX idx_aul_perf            (performance_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

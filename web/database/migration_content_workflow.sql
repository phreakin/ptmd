-- ============================================================
-- Migration: Content Workflow automation tables + worker setting
-- Run against an existing PTMD database created before
-- content_workflow* tables and automation_worker_token were added.
-- Safe to re-run.
-- ============================================================

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
-- Worker token setting for /api/social_dispatch_worker.php
-- ------------------------------------------------------------
INSERT INTO site_settings
    (setting_key, setting_value, setting_type, label, group_name, updated_at)
VALUES
    ('automation_worker_token', '', 'secret', 'Automation Worker Token', 'system', NOW())
ON DUPLICATE KEY UPDATE
    updated_at = NOW();

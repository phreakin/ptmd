-- ============================================================
-- Migration: Case Blueprints & KPI Rollups tables
-- Adds: case_blueprints, kpi_daily_rollups
--
-- case_blueprints    — reusable content strategy templates
-- kpi_daily_rollups  — pre-aggregated daily performance metrics
--
-- Safe to re-run (IF NOT EXISTS / idempotent).
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- case_blueprints — reusable editorial strategy templates
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS case_blueprints (
    id                          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    title                       VARCHAR(255)    NOT NULL,
    slug                        VARCHAR(255)    NOT NULL UNIQUE,
    blueprint_type              ENUM(
                                    'investigative','cultural','political','social',
                                    'tech','environment','humor','follow_up','custom'
                                ) NOT NULL DEFAULT 'investigative',
    status                      ENUM('active','draft','archived') NOT NULL DEFAULT 'draft',
    description                 TEXT            NULL,
    target_tone                 VARCHAR(80)     NULL,
    hook_strategy               VARCHAR(80)     NULL,
    suggested_duration_min      TINYINT UNSIGNED NULL,                      -- approximate video length in minutes
    suggested_clip_count        TINYINT UNSIGNED NULL,
    platform_priorities_json    JSON            NULL,                       -- ordered list of target platforms
    structure_json              JSON            NULL,                       -- segment/act structure definition
    brand_notes                 TEXT            NULL,
    created_by                  INT UNSIGNED    NULL,
    created_at                  DATETIME        NOT NULL,
    updated_at                  DATETIME        NOT NULL,
    CONSTRAINT fk_cbp_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_cbp_status (status),
    INDEX idx_cbp_type   (blueprint_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- kpi_daily_rollups — one row per metric per platform per day
-- Unique constraint prevents duplicate aggregation rows.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kpi_daily_rollups (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    stat_date       DATE            NOT NULL,
    platform        VARCHAR(80)     NOT NULL DEFAULT 'all',
    metric_key      VARCHAR(80)     NOT NULL,                               -- e.g. 'views', 'avg_retention', 'hook_ctr'
    metric_value    DECIMAL(15,4)   NOT NULL DEFAULT 0,
    sample_size     INT UNSIGNED    NOT NULL DEFAULT 0,                     -- number of data points aggregated
    notes           VARCHAR(255)    NULL,
    created_at      DATETIME        NOT NULL,
    UNIQUE KEY uq_kdr_date_platform_key (stat_date, platform, metric_key),
    INDEX idx_kdr_date (stat_date),
    INDEX idx_kdr_key  (metric_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

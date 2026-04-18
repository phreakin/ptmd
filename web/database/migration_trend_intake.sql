-- ============================================================
-- Migration: Trend Intake tables
-- Adds: trend_sources, trend_clusters, trend_signals
--
-- trend_sources  — configured ingest endpoints (RSS, API, etc.)
-- trend_clusters — deduplicated topic clusters with scoring
-- trend_signals  — individual raw/normalised trend signals
--
-- Safe to re-run (IF NOT EXISTS / idempotent).
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- trend_sources — where signals come from
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trend_sources (
    id                      INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    source_type             ENUM('rss','api','manual','scrape','social','editor_flag') NOT NULL DEFAULT 'manual',
    source_key              VARCHAR(80)     NOT NULL UNIQUE,                -- machine-readable identifier
    display_name            VARCHAR(120)    NOT NULL,
    endpoint_url            VARCHAR(500)    NULL,
    auth_json               JSON            NULL,                           -- encrypted credentials
    is_active               TINYINT(1)      NOT NULL DEFAULT 1,
    last_fetched_at         DATETIME        NULL,
    fetch_interval_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    config_json             JSON            NULL,                           -- source-specific config
    created_at              DATETIME        NOT NULL,
    updated_at              DATETIME        NOT NULL,
    INDEX idx_trsrc_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- trend_clusters — grouped/deduplicated topic clusters
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trend_clusters (
    id                          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    label                       VARCHAR(255)    NOT NULL,
    summary                     TEXT            NULL,
    signal_count                INT UNSIGNED    NOT NULL DEFAULT 0,
    trend_score                 DECIMAL(8,4)    NOT NULL DEFAULT 0,
    freshness_score             DECIMAL(5,2)    NOT NULL DEFAULT 0,
    shelf_life_hours            SMALLINT UNSIGNED NOT NULL DEFAULT 72,      -- hours before expiry
    risk_level                  ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
    status                      ENUM('active','expired','promoted','rejected') NOT NULL DEFAULT 'active',
    explanation_text            TEXT            NULL,
    why_it_matters              TEXT            NULL,
    risk_flags_json             JSON            NULL,
    recommended_angles_json     JSON            NULL,
    promoted_case_id            INT UNSIGNED    NULL,                       -- set when cluster becomes a case
    expires_at                  DATETIME        NULL,
    created_at                  DATETIME        NOT NULL,
    updated_at                  DATETIME        NOT NULL,
    CONSTRAINT fk_trcl_case FOREIGN KEY (promoted_case_id) REFERENCES cases(id) ON DELETE SET NULL,
    INDEX idx_trcl_status (status),
    INDEX idx_trcl_trend_score (trend_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- trend_signals — individual ingest items before/after scoring
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trend_signals (
    id                      INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    source_id               INT UNSIGNED    NULL,
    cluster_id              INT UNSIGNED    NULL,
    external_ref            VARCHAR(255)    NULL,                           -- source's own ID/URL
    raw_json                JSON            NULL,                           -- original payload
    normalized_topic        VARCHAR(255)    NOT NULL,
    dedupe_hash             VARCHAR(64)     NOT NULL UNIQUE,                -- SHA-256 of normalised content
    freshness_score         DECIMAL(5,2)    NOT NULL DEFAULT 0,
    cultural_score          DECIMAL(5,2)    NOT NULL DEFAULT 0,
    brand_fit_score         DECIMAL(5,2)    NOT NULL DEFAULT 0,
    sensitivity_score       DECIMAL(5,2)    NOT NULL DEFAULT 0,
    doc_potential_score     DECIMAL(5,2)    NOT NULL DEFAULT 0,
    clip_potential_score    DECIMAL(5,2)    NOT NULL DEFAULT 0,
    humor_score             DECIMAL(5,2)    NOT NULL DEFAULT 0,
    platform_velocity_score DECIMAL(5,2)    NOT NULL DEFAULT 0,
    trend_score             DECIMAL(8,4)    NOT NULL DEFAULT 0,             -- composite weighted score
    status                  ENUM('raw','normalized','clustered','promoted','rejected','expired') NOT NULL DEFAULT 'raw',
    explanation_text        TEXT            NULL,
    created_by              INT UNSIGNED    NULL,
    created_at              DATETIME        NOT NULL,
    updated_at              DATETIME        NOT NULL,
    CONSTRAINT fk_trsig_source  FOREIGN KEY (source_id)  REFERENCES trend_sources(id)  ON DELETE SET NULL,
    CONSTRAINT fk_trsig_cluster FOREIGN KEY (cluster_id) REFERENCES trend_clusters(id) ON DELETE SET NULL,
    CONSTRAINT fk_trsig_user    FOREIGN KEY (created_by) REFERENCES users(id)           ON DELETE SET NULL,
    INDEX idx_trsig_status  (status),
    INDEX idx_trsig_score   (trend_score),
    INDEX idx_trsig_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

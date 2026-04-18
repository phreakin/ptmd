-- ============================================================
-- Migration: Optimizer Engine tables
-- Adds: optimizer_runs, optimizer_factors, optimizer_variants,
--       optimizer_outcomes
--
-- optimizer_runs     — top-level scoring/decision record per target
-- optimizer_factors  — per-factor breakdown for a run
-- optimizer_variants — generated content variants (hooks, titles…)
-- optimizer_outcomes — measured real-world results tied to a run
--
-- Safe to re-run (IF NOT EXISTS / idempotent).
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- optimizer_runs — one scoring run per content target
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS optimizer_runs (
    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    target_type         ENUM('case','clip','idea','hook','asset') NOT NULL DEFAULT 'case',
    target_id           INT UNSIGNED    NOT NULL,                           -- polymorphic ref to target_type table
    platform            VARCHAR(80)     NOT NULL DEFAULT 'all',
    cohort              VARCHAR(80)     NOT NULL DEFAULT 'general',
    score_total         DECIMAL(8,4)    NOT NULL DEFAULT 0,                 -- weighted composite score
    confidence          DECIMAL(5,2)    NOT NULL DEFAULT 0,
    decision            ENUM('auto_recommend','human_review','fallback','rejected') NOT NULL DEFAULT 'human_review',
    requires_approval   TINYINT(1)      NOT NULL DEFAULT 1,
    approved_by         INT UNSIGNED    NULL,
    approved_at         DATETIME        NULL,
    notes               TEXT            NULL,
    created_by          INT UNSIGNED    NULL,
    created_at          DATETIME        NOT NULL,
    CONSTRAINT fk_or_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_or_creator  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_or_target  (target_type, target_id),
    INDEX idx_or_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- optimizer_factors — per-signal score breakdown for a run
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS optimizer_factors (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    run_id          INT UNSIGNED    NOT NULL,
    factor_key      VARCHAR(60)     NOT NULL,   -- e.g. trend_alignment, audience_match, retention_pred
    factor_label    VARCHAR(120)    NOT NULL,
    factor_value    DECIMAL(8,4)    NOT NULL DEFAULT 0,
    weight          DECIMAL(5,4)    NOT NULL DEFAULT 0,   -- 0.0000–1.0000
    contribution    DECIMAL(8,4)    NOT NULL DEFAULT 0,   -- factor_value * weight
    CONSTRAINT fk_of_run FOREIGN KEY (run_id) REFERENCES optimizer_runs(id) ON DELETE CASCADE,
    INDEX idx_of_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- optimizer_variants — ranked generated alternatives
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS optimizer_variants (
    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    run_id              INT UNSIGNED    NOT NULL,
    variant_type        ENUM('hook','title','caption','thumbnail','cta','clip_strategy') NOT NULL DEFAULT 'hook',
    content_text        TEXT            NULL,
    content_json        JSON            NULL,
    score               DECIMAL(8,4)    NOT NULL DEFAULT 0,
    confidence          DECIMAL(5,2)    NOT NULL DEFAULT 0,
    rank_order          TINYINT UNSIGNED NOT NULL DEFAULT 1,
    selected            TINYINT(1)      NOT NULL DEFAULT 0,    -- chosen for use
    accepted_by_human   TINYINT(1)      NULL,                  -- NULL = not yet reviewed
    rejected_by_human   TINYINT(1)      NOT NULL DEFAULT 0,
    rejection_reason    TEXT            NULL,
    performance_score   DECIMAL(8,4)    NULL,                  -- back-filled from outcomes
    created_at          DATETIME        NOT NULL,
    updated_at          DATETIME        NOT NULL,
    CONSTRAINT fk_ov_run FOREIGN KEY (run_id) REFERENCES optimizer_runs(id) ON DELETE CASCADE,
    INDEX idx_ov_run_type (run_id, variant_type),
    INDEX idx_ov_rank     (run_id, rank_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- optimizer_outcomes — real-world performance measurements
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS optimizer_outcomes (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    run_id          INT UNSIGNED    NOT NULL,
    variant_id      INT UNSIGNED    NULL,
    outcome_type    ENUM('views','likes','comments','shares','retention','ctr','completion','engagement') NOT NULL,
    platform        VARCHAR(80)     NULL,
    measured_value  DECIMAL(12,4)   NOT NULL DEFAULT 0,
    measured_at     DATETIME        NOT NULL,
    notes           TEXT            NULL,
    CONSTRAINT fk_oo_run     FOREIGN KEY (run_id)     REFERENCES optimizer_runs(id)     ON DELETE CASCADE,
    CONSTRAINT fk_oo_variant FOREIGN KEY (variant_id) REFERENCES optimizer_variants(id) ON DELETE SET NULL,
    INDEX idx_oo_run      (run_id),
    INDEX idx_oo_measured (measured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

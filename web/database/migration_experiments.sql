-- ============================================================
-- Migration: Experiments tables
-- Adds: experiment_runs, experiment_variants,
--       experiment_assignments, experiment_events
--
-- experiment_runs        — top-level A/B or multivariate experiment
-- experiment_variants    — control and treatment arms
-- experiment_assignments — which object received which variant
-- experiment_events      — individual outcome events per variant
--
-- Note: experiment_runs.winner_variant_id is a soft reference
--       (no FK constraint) to avoid ALTER TABLE idempotency issues.
--
-- Safe to re-run (IF NOT EXISTS / idempotent).
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- experiment_runs — experiment definition and result summary
-- winner_variant_id is a soft ref (no FK) — see note above
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS experiment_runs (
    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(255)    NOT NULL,
    experiment_type     ENUM('hook','title','thumbnail','caption','cta','posting_time','platform_order') NOT NULL DEFAULT 'hook',
    status              ENUM('draft','running','paused','completed','canceled') NOT NULL DEFAULT 'draft',
    hypothesis          TEXT            NULL,
    min_sample_size     INT UNSIGNED    NOT NULL DEFAULT 100,
    target_confidence   DECIMAL(5,2)    NOT NULL DEFAULT 95.00,
    started_at          DATETIME        NULL,
    ended_at            DATETIME        NULL,
    winner_variant_id   INT UNSIGNED    NULL,                               -- soft ref to experiment_variants.id
    conclusion_text     TEXT            NULL,
    created_by          INT UNSIGNED    NULL,
    created_at          DATETIME        NOT NULL,
    updated_at          DATETIME        NOT NULL,
    CONSTRAINT fk_er_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_er_status (status),
    INDEX idx_er_type   (experiment_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- experiment_variants — arms (control + treatments) per run
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS experiment_variants (
    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    experiment_id       INT UNSIGNED    NOT NULL,
    variant_key         VARCHAR(40)     NOT NULL,   -- e.g. 'control', 'variant_a', 'variant_b'
    content_text        TEXT            NULL,
    content_json        JSON            NULL,
    allocation_weight   DECIMAL(5,2)    NOT NULL DEFAULT 50.00,             -- % of traffic
    sample_count        INT UNSIGNED    NOT NULL DEFAULT 0,
    is_control          TINYINT(1)      NOT NULL DEFAULT 0,
    created_at          DATETIME        NOT NULL,
    CONSTRAINT fk_ev_experiment FOREIGN KEY (experiment_id) REFERENCES experiment_runs(id) ON DELETE CASCADE,
    INDEX idx_ev_experiment (experiment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- experiment_assignments — object-to-variant allocation log
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS experiment_assignments (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    experiment_id   INT UNSIGNED    NOT NULL,
    variant_id      INT UNSIGNED    NOT NULL,
    object_type     VARCHAR(60)     NOT NULL,                               -- e.g. 'post', 'clip', 'user_session'
    object_id       INT UNSIGNED    NOT NULL,
    assigned_at     DATETIME        NOT NULL,
    CONSTRAINT fk_eassign_exp     FOREIGN KEY (experiment_id) REFERENCES experiment_runs(id)     ON DELETE CASCADE,
    CONSTRAINT fk_eassign_variant FOREIGN KEY (variant_id)    REFERENCES experiment_variants(id) ON DELETE CASCADE,
    INDEX idx_eassign_exp_obj (experiment_id, object_type, object_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- experiment_events — outcome events for statistical analysis
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS experiment_events (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    experiment_id   INT UNSIGNED    NOT NULL,
    variant_id      INT UNSIGNED    NOT NULL,
    event_type      VARCHAR(60)     NOT NULL,                               -- e.g. 'view', 'click', 'conversion'
    object_type     VARCHAR(60)     NULL,
    object_id       INT UNSIGNED    NULL,
    user_hash       VARCHAR(64)     NULL,                                   -- anonymised visitor identifier
    value           DECIMAL(12,4)   NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL,
    CONSTRAINT fk_ee_experiment FOREIGN KEY (experiment_id) REFERENCES experiment_runs(id)     ON DELETE CASCADE,
    CONSTRAINT fk_ee_variant    FOREIGN KEY (variant_id)    REFERENCES experiment_variants(id) ON DELETE CASCADE,
    INDEX idx_ee_experiment (experiment_id),
    INDEX idx_ee_variant    (variant_id),
    INDEX idx_ee_created    (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

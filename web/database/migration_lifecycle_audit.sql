-- ============================================================
-- Migration: Lifecycle Audit tables
-- Adds: content_state_transitions, editorial_approvals,
--       override_actions
--
-- content_state_transitions — append-only state machine audit log
-- editorial_approvals       — human review/approval request records
-- override_actions          — manual override log with before/after
--
-- Safe to re-run (IF NOT EXISTS / idempotent).
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- content_state_transitions — immutable audit trail
-- actor_id has NO FK constraint intentionally: the log must
-- remain intact even if the user row is deleted.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS content_state_transitions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type     ENUM(
                        'case','video_clip','hook','asset','clip_blueprint',
                        'video_blueprint','post_job','idea','trend_signal',
                        'experiment','clip_instance','video_instance'
                    ) NOT NULL,
    entity_id       INT UNSIGNED    NOT NULL,
    from_state      VARCHAR(60)     NULL,
    to_state        VARCHAR(60)     NOT NULL,
    actor_type      ENUM('human','system','ai','cron') NOT NULL DEFAULT 'system',
    actor_id        INT UNSIGNED    NULL,                                   -- soft ref to users.id (no FK — append-only safety)
    reason          TEXT            NULL,
    meta_json       JSON            NULL,
    trace_id        VARCHAR(64)     NULL,
    created_at      DATETIME        NOT NULL,
    INDEX idx_cst_entity   (entity_type, entity_id),
    INDEX idx_cst_created  (created_at),
    INDEX idx_cst_to_state (to_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- editorial_approvals — workflow approval request lifecycle
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS editorial_approvals (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    entity_type     VARCHAR(80)     NOT NULL,
    entity_id       INT UNSIGNED    NOT NULL,
    request_type    VARCHAR(80)     NOT NULL,
    requested_by    INT UNSIGNED    NULL,
    approved_by     INT UNSIGNED    NULL,
    status          ENUM('pending','approved','rejected','expired','canceled') NOT NULL DEFAULT 'pending',
    reason          TEXT            NULL,
    expires_at      DATETIME        NULL,
    created_at      DATETIME        NOT NULL,
    decided_at      DATETIME        NULL,
    CONSTRAINT fk_ea_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ea_approver  FOREIGN KEY (approved_by)  REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ea_entity  (entity_type, entity_id),
    INDEX idx_ea_status  (status),
    INDEX idx_ea_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- override_actions — audited manual value overrides
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS override_actions (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    entity_type     VARCHAR(80)     NOT NULL,
    entity_id       INT UNSIGNED    NOT NULL,
    override_type   VARCHAR(80)     NOT NULL,
    original_value  TEXT            NULL,
    override_value  TEXT            NULL,
    reason          TEXT            NOT NULL,
    performed_by    INT UNSIGNED    NULL,
    created_at      DATETIME        NOT NULL,
    CONSTRAINT fk_oa_performer FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_oa_entity       (entity_type, entity_id),
    INDEX idx_oa_performed_by (performed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Migration: Copilot Actions tables
-- Adds: ai_assistant_context_refs, ai_assistant_actions,
--       ai_assistant_action_logs, ai_assistant_explanations
--
-- ai_assistant_context_refs   — entities surfaced as context in a session
-- ai_assistant_actions        — proposed/executed copilot actions
-- ai_assistant_action_logs    — immutable execution log per action
-- ai_assistant_explanations   — reasoning/confidence records per message
--
-- Safe to re-run (IF NOT EXISTS / idempotent).
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- ai_assistant_context_refs — entities linked to a session/message
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_assistant_context_refs (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    session_id      INT UNSIGNED    NOT NULL,
    message_id      INT UNSIGNED    NULL,
    ref_table       VARCHAR(80)     NOT NULL,                               -- e.g. 'cases', 'hooks', 'video_clips'
    ref_id          INT UNSIGNED    NOT NULL,
    relevance_score DECIMAL(5,2)    NOT NULL DEFAULT 0,
    ref_label       VARCHAR(255)    NULL,
    created_at      DATETIME        NOT NULL,
    CONSTRAINT fk_acr_session FOREIGN KEY (session_id) REFERENCES ai_assistant_sessions(id)  ON DELETE CASCADE,
    CONSTRAINT fk_acr_message FOREIGN KEY (message_id) REFERENCES ai_assistant_messages(id)  ON DELETE SET NULL,
    INDEX idx_acr_session (session_id),
    INDEX idx_acr_ref     (ref_table, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- ai_assistant_actions — suggested or executed copilot actions
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_assistant_actions (
    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    session_id          INT UNSIGNED    NOT NULL,
    message_id          INT UNSIGNED    NULL,
    action_type         VARCHAR(80)     NOT NULL,   -- create_case_draft, generate_hooks, queue_post, etc.
    target_table        VARCHAR(80)     NULL,
    target_id           INT UNSIGNED    NULL,
    payload_json        JSON            NULL,
    risk_level          ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
    requires_approval   TINYINT(1)      NOT NULL DEFAULT 1,
    status              ENUM('suggested','pending_approval','approved','rejected','executed','failed','canceled') NOT NULL DEFAULT 'suggested',
    suggested_by_model  VARCHAR(80)     NULL,                               -- e.g. 'gpt-4o', 'claude-3-5-sonnet'
    created_at          DATETIME        NOT NULL,
    updated_at          DATETIME        NOT NULL,
    CONSTRAINT fk_aaa_session FOREIGN KEY (session_id) REFERENCES ai_assistant_sessions(id)  ON DELETE CASCADE,
    CONSTRAINT fk_aaa_message FOREIGN KEY (message_id) REFERENCES ai_assistant_messages(id)  ON DELETE SET NULL,
    INDEX idx_aaa_session (session_id),
    INDEX idx_aaa_status  (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- ai_assistant_action_logs — append-only execution history
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_assistant_action_logs (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    action_id       INT UNSIGNED    NOT NULL,
    performed_by    INT UNSIGNED    NULL,
    status          ENUM('executed','failed','rolled_back') NOT NULL DEFAULT 'executed',
    result_json     JSON            NULL,
    error_message   TEXT            NULL,
    created_at      DATETIME        NOT NULL,
    CONSTRAINT fk_aaal_action    FOREIGN KEY (action_id)    REFERENCES ai_assistant_actions(id) ON DELETE CASCADE,
    CONSTRAINT fk_aaal_performer FOREIGN KEY (performed_by) REFERENCES users(id)                ON DELETE SET NULL,
    INDEX idx_aaal_action (action_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- ai_assistant_explanations — XAI / reasoning transparency records
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_assistant_explanations (
    id                          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    session_id                  INT UNSIGNED    NOT NULL,
    message_id                  INT UNSIGNED    NULL,
    context_refs_json           JSON            NULL,                       -- summarised context used
    factors_json                JSON            NULL,                       -- weighted scoring factors
    confidence                  DECIMAL(5,2)    NULL,
    human_review_recommended    TINYINT(1)      NOT NULL DEFAULT 0,
    alternatives_json           JSON            NULL,
    data_sources_json           JSON            NULL,
    created_at                  DATETIME        NOT NULL,
    CONSTRAINT fk_aae_session FOREIGN KEY (session_id) REFERENCES ai_assistant_sessions(id)  ON DELETE CASCADE,
    CONSTRAINT fk_aae_message FOREIGN KEY (message_id) REFERENCES ai_assistant_messages(id)  ON DELETE SET NULL,
    INDEX idx_aae_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

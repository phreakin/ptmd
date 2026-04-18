-- ============================================================
-- Migration: Observability & Ops tables
-- Adds: ptmd_events, job_executions, webhook_deliveries,
--       service_health_checks, ai_usage_costs
--
-- ptmd_events           — structured domain event log (fan-out bus)
-- job_executions        — background job run tracking with retry
-- webhook_deliveries    — outbound/inbound webhook delivery log
-- service_health_checks — periodic health check snapshots
-- ai_usage_costs        — token usage and cost tracking per call
--
-- Safe to re-run (IF NOT EXISTS / idempotent).
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- ptmd_events — append-only structured domain event log
-- user_id is a soft ref (no FK) so deleting a user does not
-- cascade-delete audit history.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ptmd_events (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_name          VARCHAR(80)     NOT NULL,
    event_category      VARCHAR(60)     NOT NULL,   -- content, ai, posting, analytics, system, user, experiment
    module              VARCHAR(60)     NOT NULL,
    object_type         VARCHAR(60)     NULL,
    object_id           INT UNSIGNED    NULL,
    parent_object_type  VARCHAR(60)     NULL,
    parent_object_id    INT UNSIGNED    NULL,
    user_id             INT UNSIGNED    NULL,        -- soft ref to users.id
    session_id          VARCHAR(64)     NULL,
    trace_id            VARCHAR(64)     NULL,
    status              VARCHAR(40)     NULL,
    source              VARCHAR(80)     NULL,
    before_state        VARCHAR(60)     NULL,
    after_state         VARCHAR(60)     NULL,
    confidence          DECIMAL(5,2)    NULL,
    metadata_json       JSON            NULL,
    created_at          DATETIME        NOT NULL,
    INDEX idx_pe_event_created  (event_name, created_at),
    INDEX idx_pe_module_created (module, created_at),
    INDEX idx_pe_object         (object_type, object_id),
    INDEX idx_pe_user           (user_id),
    INDEX idx_pe_trace          (trace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- job_executions — background worker job lifecycle
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS job_executions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_type        VARCHAR(80)     NOT NULL,
    trace_id        VARCHAR(64)     NULL,
    status          ENUM('pending','running','completed','failed','canceled') NOT NULL DEFAULT 'pending',
    payload_json    JSON            NULL,
    result_json     JSON            NULL,
    error_message   TEXT            NULL,
    retry_count     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_retries     TINYINT UNSIGNED NOT NULL DEFAULT 3,
    next_retry_at   DATETIME        NULL,
    worker_id       VARCHAR(80)     NULL,                                   -- identifies which worker processed this
    started_at      DATETIME        NULL,
    finished_at     DATETIME        NULL,
    created_at      DATETIME        NOT NULL,
    INDEX idx_je_type_status (job_type, status),
    INDEX idx_je_status      (status),
    INDEX idx_je_trace       (trace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- webhook_deliveries — outbound and inbound webhook log
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    webhook_type        VARCHAR(80)     NOT NULL,   -- 'outbound' or 'inbound'
    event_name          VARCHAR(80)     NOT NULL,
    endpoint_url        VARCHAR(500)    NOT NULL,
    payload_json        JSON            NULL,
    signature           VARCHAR(128)    NULL,                               -- HMAC signature
    status              ENUM('pending','delivered','failed','canceled') NOT NULL DEFAULT 'pending',
    attempt_count       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at     DATETIME        NULL,
    next_attempt_at     DATETIME        NULL,
    response_code       SMALLINT        NULL,
    response_body       TEXT            NULL,
    error_message       TEXT            NULL,
    created_at          DATETIME        NOT NULL,
    updated_at          DATETIME        NOT NULL,
    INDEX idx_wd_status  (status),
    INDEX idx_wd_event   (event_name),
    INDEX idx_wd_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- service_health_checks — rolling health snapshot per service
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS service_health_checks (
    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    service_name        VARCHAR(80)     NOT NULL,
    status              ENUM('healthy','degraded','down','unknown') NOT NULL DEFAULT 'unknown',
    message             TEXT            NULL,
    response_time_ms    INT UNSIGNED    NULL,
    checked_at          DATETIME        NOT NULL,
    INDEX idx_shc_service (service_name, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- ai_usage_costs — token consumption and cost per AI call
-- session_id and generation_id are soft refs (no FK constraints)
-- to decouple cost tracking from session lifecycle.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_usage_costs (
    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    feature             VARCHAR(80)     NOT NULL,                           -- e.g. 'hook_gen', 'trend_score'
    model               VARCHAR(80)     NOT NULL,
    session_id          INT UNSIGNED    NULL,                               -- soft ref to ai_assistant_sessions.id
    generation_id       INT UNSIGNED    NULL,                               -- soft ref to ai_generations.id
    prompt_tokens       INT UNSIGNED    NOT NULL DEFAULT 0,
    response_tokens     INT UNSIGNED    NOT NULL DEFAULT 0,
    total_tokens        INT UNSIGNED    NOT NULL DEFAULT 0,
    estimated_cost_usd  DECIMAL(10,6)   NOT NULL DEFAULT 0,
    created_at          DATETIME        NOT NULL,
    INDEX idx_auc_feature (feature),
    INDEX idx_auc_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

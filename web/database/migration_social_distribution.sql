-- ============================================================
-- PTMD Social Distribution Migration
-- Adds observability columns to social_post_logs and social_post_queue.
-- Run ONCE after schema.sql.  Idempotent: uses IF NOT EXISTS guards.
-- ============================================================

-- ── 1. Extend social_post_logs with dispatch observability columns ────────────

ALTER TABLE social_post_logs
    ADD COLUMN IF NOT EXISTS latency_ms
        INT UNSIGNED NULL
        COMMENT 'Wall-clock milliseconds from dispatch start to platform API response'
        AFTER status,

    ADD COLUMN IF NOT EXISTS correlation_id
        VARCHAR(36) NULL
        COMMENT 'UUID v4 linking this log row to a single dispatch attempt across retries'
        AFTER latency_ms,

    ADD COLUMN IF NOT EXISTS retry_attempt
        TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Which attempt number this row records (0 = first try)'
        AFTER correlation_id;

ALTER TABLE social_post_logs
    ADD INDEX IF NOT EXISTS idx_spl_correlation (correlation_id);

-- ── 2. Extend social_post_queue with error classification column ──────────────

ALTER TABLE social_post_queue
    ADD COLUMN IF NOT EXISTS error_class
        VARCHAR(80) NULL
        COMMENT 'Category of the last failure: rate_limit | auth | network | validation | unknown'
        AFTER last_error;

ALTER TABLE social_post_queue
    ADD INDEX IF NOT EXISTS idx_queue_error_class (error_class);

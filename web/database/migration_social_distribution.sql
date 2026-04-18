-- ============================================================
-- PTMD Migration: Social Distribution Upgrade
-- Adds observability + retry columns to social queue tables.
-- Safe to run on existing installs — uses IF NOT EXISTS guards.
-- Run after schema.sql has already been applied.
-- ============================================================

-- social_post_queue: retry / error-class tracking
ALTER TABLE social_post_queue
    ADD COLUMN IF NOT EXISTS retry_count  INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Number of dispatch attempts made so far'
        AFTER last_error,
    ADD COLUMN IF NOT EXISTS error_class  VARCHAR(20)  NULL
        COMMENT 'transient | rate_limit | auth | policy | unknown'
        AFTER retry_count,
    ADD COLUMN IF NOT EXISTS retry_after  DATETIME     NULL
        COMMENT 'Earliest timestamp for the next retry attempt'
        AFTER error_class;

-- Add index for the cron scheduler retry query (if not already present)
ALTER TABLE social_post_queue
    ADD INDEX IF NOT EXISTS idx_retry (status, retry_after);

-- social_post_logs: observability columns
ALTER TABLE social_post_logs
    ADD COLUMN IF NOT EXISTS latency_ms      INT UNSIGNED  NULL
        COMMENT 'Adapter round-trip latency in milliseconds'
        AFTER status,
    ADD COLUMN IF NOT EXISTS correlation_id  VARCHAR(60)   NULL
        COMMENT 'Tracing ID linking queue item to log entries (e.g. ptmd-q42-a3f9c1)'
        AFTER latency_ms,
    ADD COLUMN IF NOT EXISTS retry_attempt   INT UNSIGNED  NOT NULL DEFAULT 0
        COMMENT '0 = first attempt; increments on each retry'
        AFTER correlation_id;

-- Add index for correlation lookups (if not already present)
ALTER TABLE social_post_logs
    ADD INDEX IF NOT EXISTS idx_spl_correlation (correlation_id);

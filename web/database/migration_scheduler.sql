-- ============================================================
-- PTMD Scheduler Migration
-- Extends schema for automated scheduling + queue dispatch.
-- Run this ONCE against an existing ptmd database after schema.sql.
-- Idempotent: uses IF NOT EXISTS / IF EXISTS guards.
-- ============================================================

-- ── 1. Extend social_post_schedules with recurrence + audit metadata ──────────

ALTER TABLE social_post_schedules
    ADD COLUMN IF NOT EXISTS recurrence_type  ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'weekly'
        COMMENT 'How often this rule fires'
        AFTER timezone,
    ADD COLUMN IF NOT EXISTS last_generated_at DATETIME NULL
        COMMENT 'Timestamp of the most recent queue-generation pass for this rule'
        AFTER recurrence_type,
    ADD COLUMN IF NOT EXISTS last_run_status   VARCHAR(50) NULL
        COMMENT 'Result of the last generation pass: ok | skipped | error'
        AFTER last_generated_at,
    ADD COLUMN IF NOT EXISTS created_by        INT UNSIGNED NULL
        COMMENT 'Admin user who created this schedule rule'
        AFTER last_run_status;

-- Index for efficient due-schedule selection
ALTER TABLE social_post_schedules
    ADD INDEX IF NOT EXISTS idx_sched_active_dow (is_active, day_of_week);

-- ── 2. Extend social_post_queue with retry + traceability columns ─────────────

ALTER TABLE social_post_queue
    ADD COLUMN IF NOT EXISTS retry_count     TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Number of dispatch attempts made so far'
        AFTER last_error,
    ADD COLUMN IF NOT EXISTS retry_after     DATETIME NULL
        COMMENT 'Do not retry before this timestamp (exponential backoff)'
        AFTER retry_count,
    ADD COLUMN IF NOT EXISTS schedule_id     INT UNSIGNED NULL
        COMMENT 'FK to social_post_schedules row that generated this item (NULL = manual)'
        AFTER retry_after,
    ADD COLUMN IF NOT EXISTS auto_generated  TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = created by scheduler automation; 0 = created by admin UI'
        AFTER schedule_id;

-- Index for retry-due selection
ALTER TABLE social_post_queue
    ADD INDEX IF NOT EXISTS idx_queue_retry    (status, retry_after),
    ADD INDEX IF NOT EXISTS idx_queue_schedule (schedule_id);

-- ── 3. Scheduler lock row (prevents concurrent runs) ─────────────────────────
-- Uses site_settings as a lightweight lease; no extra table needed.

-- ── 4. Seed scheduler settings (idempotent UPSERT) ───────────────────────────
INSERT INTO site_settings
    (setting_key, setting_value, setting_type, label, group_name, updated_at)
VALUES
    ('scheduler_secret',          '',   'secret', 'Scheduler Secret Token',           'system', NOW()),
    ('scheduler_enabled',         '1',  'bool',   'Enable Automated Scheduler',       'system', NOW()),
    ('scheduler_max_retries',     '3',  'int',    'Scheduler Max Retry Attempts',     'system', NOW()),
    ('scheduler_retry_interval',  '15', 'int',    'Scheduler Retry Interval (min)',   'system', NOW()),
    ('scheduler_horizon_days',    '30', 'int',    'Queue Generation Horizon (days)',  'system', NOW()),
    ('scheduler_lock_expires',    '',   'string', 'Scheduler Lock (auto-managed)',    'system', NOW()),
    ('scheduler_ip_allowlist',    '',   'string', 'Scheduler IP Allowlist (comma)',   'system', NOW()),
    ('scheduler_content_auto',    '0',  'bool',   'Auto-fill Captions from Platform Prefs', 'system', NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- ============================================================
-- PTMD Social Account Onboarding Migration
-- Extends social_accounts with token lifecycle + health columns.
-- Adds account_id FK on social_post_queue for dispatch accountability.
-- Run ONCE after schema.sql.  Idempotent: uses IF NOT EXISTS guards.
-- ============================================================

-- ── 1. Extend social_accounts with onboarding lifecycle columns ───────────────

ALTER TABLE social_accounts
    ADD COLUMN IF NOT EXISTS status
        ENUM('active','expired','revoked','error') NOT NULL DEFAULT 'active'
        COMMENT 'OAuth / API token status'
        AFTER is_active,

    ADD COLUMN IF NOT EXISTS token_expires_at
        DATETIME NULL
        COMMENT 'When the access token expires (NULL = non-expiring / unknown)'
        AFTER status,

    ADD COLUMN IF NOT EXISTS token_scope
        TEXT NULL
        COMMENT 'Space-separated list of granted OAuth scopes'
        AFTER token_expires_at,

    ADD COLUMN IF NOT EXISTS last_health_check_at
        DATETIME NULL
        COMMENT 'Timestamp of the most recent automated health check'
        AFTER token_scope,

    ADD COLUMN IF NOT EXISTS last_error
        TEXT NULL
        COMMENT 'Last error message from health check or dispatch attempt'
        AFTER last_health_check_at,

    ADD COLUMN IF NOT EXISTS onboarding_step
        VARCHAR(50) NULL
        COMMENT 'Wizard step token: credentials | scopes | policy | complete'
        AFTER last_error,

    ADD COLUMN IF NOT EXISTS onboarding_completed_at
        DATETIME NULL
        COMMENT 'Timestamp when onboarding wizard was finished'
        AFTER onboarding_step;

-- Index for expiry checks
ALTER TABLE social_accounts
    ADD INDEX IF NOT EXISTS idx_sa_status         (status),
    ADD INDEX IF NOT EXISTS idx_sa_token_expires  (token_expires_at),
    ADD INDEX IF NOT EXISTS idx_sa_platform       (platform);

-- ── 2. Extend social_post_queue with account_id (which account posted this) ───

ALTER TABLE social_post_queue
    ADD COLUMN IF NOT EXISTS account_id
        INT UNSIGNED NULL
        COMMENT 'FK to social_accounts row used for this dispatch attempt (NULL = unassigned)'
        AFTER schedule_id;

ALTER TABLE social_post_queue
    ADD INDEX IF NOT EXISTS idx_queue_account (account_id);

-- ── 3. Seed default social_accounts stubs for all 8 PTMD platforms ───────────
--      These are placeholder rows; operators fill in auth_config_json via the UI.

INSERT INTO social_accounts (platform, handle, is_active, status, created_at, updated_at)
VALUES
    ('YouTube',             '@papertrailmd',  0, 'active', NOW(), NOW()),
    ('YouTube Shorts',      '@papertrailmd',  0, 'active', NOW(), NOW()),
    ('TikTok',              '@papertrailmd',  0, 'active', NOW(), NOW()),
    ('Instagram Reels',     'papertrailmd',   0, 'active', NOW(), NOW()),
    ('Facebook Reels',      'papertrailmd',   0, 'active', NOW(), NOW()),
    ('Snapchat Spotlight',  'papertrailmd',   0, 'active', NOW(), NOW()),
    ('X',                   '@papertrailmd',  0, 'active', NOW(), NOW()),
    ('Pinterest Idea Pins', 'papertrailmd',   0, 'active', NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

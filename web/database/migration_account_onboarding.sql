-- ============================================================
-- PTMD Migration: Account Onboarding + A/B Variant Support
-- Adds platform onboarding lifecycle columns to social_accounts,
-- policy checklist to social_platform_preferences, and A/B
-- variant fields to social_post_queue.
-- Safe to run on existing installs — uses IF NOT EXISTS guards.
-- Run after migration_social_distribution.sql.
-- ============================================================

-- social_accounts: onboarding lifecycle columns
ALTER TABLE social_accounts
    ADD COLUMN IF NOT EXISTS onboard_status       ENUM('pending','connected','active','error','deactivated')
        NOT NULL DEFAULT 'pending'
        COMMENT 'Current lifecycle state of the account connection'
        AFTER is_active,
    ADD COLUMN IF NOT EXISTS token_expires_at      DATETIME NULL
        COMMENT 'When the OAuth access token expires; NULL = non-expiring or unknown'
        AFTER onboard_status,
    ADD COLUMN IF NOT EXISTS permissions_json      JSON NULL
        COMMENT 'Array of OAuth scopes granted at last authorization'
        AFTER token_expires_at,
    ADD COLUMN IF NOT EXISTS required_scopes_json  JSON NULL
        COMMENT 'Array of OAuth scopes required for posting on this platform'
        AFTER permissions_json,
    ADD COLUMN IF NOT EXISTS last_health_check_at  DATETIME NULL
        COMMENT 'Timestamp of the last health check run'
        AFTER required_scopes_json,
    ADD COLUMN IF NOT EXISTS health_status         ENUM('ok','warning','error','unknown')
        NOT NULL DEFAULT 'unknown'
        COMMENT 'Result of the last health check'
        AFTER last_health_check_at,
    ADD COLUMN IF NOT EXISTS health_notes_json     JSON NULL
        COMMENT 'Array of human-readable notes from the last health check'
        AFTER health_status,
    ADD COLUMN IF NOT EXISTS geo_restrict          VARCHAR(255) NULL
        COMMENT 'Comma-separated ISO 3166-1 alpha-2 country codes; NULL = unrestricted'
        AFTER health_notes_json,
    ADD COLUMN IF NOT EXISTS age_restrict          ENUM('none','18+') NOT NULL DEFAULT 'none'
        COMMENT 'Minimum age gate applied to all posts from this account'
        AFTER geo_restrict,
    ADD COLUMN IF NOT EXISTS visibility_default    ENUM('public','private','unlisted','friends')
        NOT NULL DEFAULT 'public'
        COMMENT 'Default visibility setting for new posts from this account'
        AFTER age_restrict,
    ADD COLUMN IF NOT EXISTS policy_checklist_json JSON NULL
        COMMENT 'Platform policy compliance flags: music_ok, disclosure_ok, no_prohibited_content, etc.'
        AFTER visibility_default;

-- Add index for platform + active lookups
ALTER TABLE social_accounts
    ADD INDEX IF NOT EXISTS idx_sa_platform_active (platform, is_active);

-- social_platform_preferences: compliance checklist
ALTER TABLE social_platform_preferences
    ADD COLUMN IF NOT EXISTS policy_checklist_json JSON NULL
        COMMENT 'Platform-level compliance flags: music_ok, disclosure_ok, prohibited_categories_reviewed'
        AFTER is_enabled;

-- social_post_queue: A/B variant support
ALTER TABLE social_post_queue
    ADD COLUMN IF NOT EXISTS hashtags      VARCHAR(500) NULL
        COMMENT 'Primary variant hashtags (space-separated)'
        AFTER caption,
    ADD COLUMN IF NOT EXISTS caption_b     TEXT NULL
        COMMENT 'A/B variant: alternate caption body'
        AFTER hashtags,
    ADD COLUMN IF NOT EXISTS hashtags_b    VARCHAR(500) NULL
        COMMENT 'A/B variant: alternate hashtags'
        AFTER caption_b,
    ADD COLUMN IF NOT EXISTS active_variant ENUM('a','b') NOT NULL DEFAULT 'a'
        COMMENT 'Which variant (a or b) was used when dispatching this item'
        AFTER hashtags_b;

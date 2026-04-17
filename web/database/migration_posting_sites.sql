-- ============================================================
-- Migration: Posting Sites tables
-- Run against an existing PTMD database created from schema.sql
-- before the posting_sites / site_posting_options tables were added.
-- Safe to re-run (CREATE TABLE IF NOT EXISTS + INSERT IGNORE).
-- ============================================================

-- Create the canonical sites table
CREATE TABLE IF NOT EXISTS posting_sites (
    id           INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
    site_key     VARCHAR(80)       NOT NULL UNIQUE,
    display_name VARCHAR(120)      NOT NULL,
    is_active    TINYINT(1)        NOT NULL DEFAULT 1,
    sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at   DATETIME          NOT NULL,
    updated_at   DATETIME          NOT NULL,
    INDEX idx_ps_active_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create the per-site posting options table
CREATE TABLE IF NOT EXISTS site_posting_options (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id                INT UNSIGNED NOT NULL UNIQUE,
    default_content_type   VARCHAR(80)  NULL,
    default_caption_prefix TEXT         NULL,
    default_hashtags       VARCHAR(255) NULL,
    default_status         ENUM('draft','queued','scheduled') NOT NULL DEFAULT 'queued',
    created_at             DATETIME     NOT NULL,
    updated_at             DATETIME     NOT NULL,
    CONSTRAINT fk_spo_site FOREIGN KEY (site_id) REFERENCES posting_sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- Backfill: insert a posting_sites row for every distinct platform name
-- found in social_platform_preferences, social_post_queue, and
-- social_post_schedules.  site_key is derived from the display name
-- (lowercase, spaces → underscores) to match the dispatch registry.
-- Uses INSERT IGNORE so re-runs are harmless.
-- -----------------------------------------------------------------------
INSERT IGNORE INTO posting_sites (site_key, display_name, is_active, sort_order, created_at, updated_at)
SELECT
    LOWER(REPLACE(platform, ' ', '_')) AS site_key,
    platform                            AS display_name,
    1                                   AS is_active,
    0                                   AS sort_order,
    NOW(),
    NOW()
FROM (
    SELECT DISTINCT platform FROM social_platform_preferences  WHERE platform <> ''
    UNION
    SELECT DISTINCT platform FROM social_post_queue            WHERE platform <> ''
    UNION
    SELECT DISTINCT platform FROM social_post_schedules        WHERE platform <> ''
) AS combined_platforms;

-- -----------------------------------------------------------------------
-- Backfill: copy posting options from social_platform_preferences
-- into site_posting_options for each matched site.
-- -----------------------------------------------------------------------
INSERT IGNORE INTO site_posting_options
    (site_id, default_content_type, default_caption_prefix, default_hashtags, default_status, created_at, updated_at)
SELECT
    ps.id,
    spp.default_content_type,
    spp.default_caption_prefix,
    spp.default_hashtags,
    spp.default_status,
    NOW(),
    NOW()
FROM social_platform_preferences spp
JOIN posting_sites ps ON ps.display_name = spp.platform;

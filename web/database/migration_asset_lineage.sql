-- ============================================================
-- Migration: Asset Lineage tables
-- Adds: asset_relations, asset_versions, asset_fingerprints
--
-- asset_relations     — directed graph of relationships between any entities
-- asset_versions      — version history for assets (file-level)
-- asset_fingerprints  — hash-based deduplication registry
--
-- Safe to re-run (IF NOT EXISTS / idempotent).
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- asset_relations — polymorphic directed-graph edges
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS asset_relations (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    source_table    VARCHAR(80)     NOT NULL,
    source_id       INT UNSIGNED    NOT NULL,
    target_table    VARCHAR(80)     NOT NULL,
    target_id       INT UNSIGNED    NOT NULL,
    relation_type   ENUM('derived_from','used_in','variant_of','replaces','depends_on','generated_from') NOT NULL DEFAULT 'used_in',
    meta_json       JSON            NULL,
    created_at      DATETIME        NOT NULL,
    INDEX idx_ar_source (source_table, source_id),
    INDEX idx_ar_target (target_table, target_id),
    INDEX idx_ar_type   (relation_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- asset_versions — per-file version history for an asset
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS asset_versions (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    asset_id        INT UNSIGNED    NOT NULL,
    version_number  TINYINT UNSIGNED NOT NULL DEFAULT 1,
    file_path       VARCHAR(255)    NOT NULL,
    size_bytes      BIGINT UNSIGNED NULL,
    file_hash       VARCHAR(64)     NULL,                                   -- SHA-256
    change_note     VARCHAR(255)    NULL,
    created_by      INT UNSIGNED    NULL,
    created_at      DATETIME        NOT NULL,
    CONSTRAINT fk_av_asset   FOREIGN KEY (asset_id)   REFERENCES assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_av_creator FOREIGN KEY (created_by) REFERENCES users(id)  ON DELETE SET NULL,
    INDEX idx_av_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- asset_fingerprints — hash registry for duplicate detection
-- Self-referencing FK (duplicate_of) resolved after table create
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS asset_fingerprints (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    asset_id        INT UNSIGNED    NULL,
    file_path       VARCHAR(255)    NOT NULL,
    file_hash       VARCHAR(64)     NOT NULL UNIQUE,                        -- exact-match dedup key
    perceptual_hash VARCHAR(64)     NULL,                                   -- near-duplicate image/video hash
    file_size       BIGINT UNSIGNED NULL,
    duplicate_of    INT UNSIGNED    NULL,                                   -- self-ref: points to canonical record
    created_at      DATETIME        NOT NULL,
    CONSTRAINT fk_afp_asset      FOREIGN KEY (asset_id)     REFERENCES assets(id)             ON DELETE SET NULL,
    CONSTRAINT fk_afp_duplicate  FOREIGN KEY (duplicate_of) REFERENCES asset_fingerprints(id) ON DELETE SET NULL,
    INDEX idx_afp_asset  (asset_id),
    INDEX idx_afp_phash  (perceptual_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

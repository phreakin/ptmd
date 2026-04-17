-- ============================================================
-- Migration: Social Platform Images
-- Run after schema.sql if upgrading an existing installation.
-- ============================================================

CREATE TABLE IF NOT EXISTS social_platform_images (
    id                  INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    platform            VARCHAR(80)   NOT NULL,
    image_type          VARCHAR(80)   NOT NULL,
    image_path          VARCHAR(255)  NOT NULL,
    width               INT UNSIGNED  NULL,
    height              INT UNSIGNED  NULL,
    file_size           BIGINT UNSIGNED NULL,
    is_valid            TINYINT(1)    NOT NULL DEFAULT 0,
    validation_errors   JSON          NULL,
    created_at          DATETIME      NOT NULL,
    updated_at          DATETIME      NOT NULL,
    INDEX idx_spi_platform (platform),
    INDEX idx_spi_platform_type (platform, image_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

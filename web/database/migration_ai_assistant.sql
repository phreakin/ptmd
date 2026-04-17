-- ============================================================
-- Migration: Admin Copilot tables
-- Run against an existing PTMD database that was created from
-- schema.sql before the ai_assistant tables were added.
-- ============================================================

CREATE TABLE IF NOT EXISTS ai_assistant_sessions (
    id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED  NULL,
    title       VARCHAR(255)  NOT NULL DEFAULT 'New Conversation',
    created_at  DATETIME      NOT NULL,
    updated_at  DATETIME      NOT NULL,
    CONSTRAINT fk_aas_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_assistant_messages (
    id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    session_id  INT UNSIGNED  NOT NULL,
    role        ENUM('user','assistant') NOT NULL,
    content     MEDIUMTEXT    NOT NULL,
    created_at  DATETIME      NOT NULL,
    CONSTRAINT fk_aam_session FOREIGN KEY (session_id) REFERENCES ai_assistant_sessions(id) ON DELETE CASCADE,
    INDEX idx_aam_session_created (session_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

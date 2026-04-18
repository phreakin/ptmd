-- ============================================================
-- PTMD Migration — Chat Phase 1 Foundation
-- Adds public chat users, rooms, reactions, bans, and expanded
-- message/moderation metadata for older installs.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS chat_users (
    id               INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    username         VARCHAR(50)   NOT NULL UNIQUE,
    email            VARCHAR(150)  NULL UNIQUE,
    password_hash    VARCHAR(255)  NULL,
    display_name     VARCHAR(80)   NOT NULL,
    avatar_color     VARCHAR(7)    NOT NULL DEFAULT '#2EC4B6',
    role             ENUM('guest','registered','moderator','admin','super_admin') NOT NULL DEFAULT 'registered',
    status           ENUM('active','muted','banned') NOT NULL DEFAULT 'active',
    muted_until      DATETIME      NULL,
    badge_label      VARCHAR(50)   NULL,
    remember_token   VARCHAR(64)   NULL,
    last_message_at  DATETIME      NULL,
    created_at       DATETIME      NOT NULL,
    updated_at       DATETIME      NOT NULL,
    INDEX idx_cu_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_rooms (
    id                  INT UNSIGNED       AUTO_INCREMENT PRIMARY KEY,
    slug                VARCHAR(120)       NOT NULL UNIQUE,
    name                VARCHAR(255)       NOT NULL,
    description         TEXT               NULL,
    case_id             INT UNSIGNED       NULL,
    is_live             TINYINT(1)         NOT NULL DEFAULT 0,
    slow_mode_seconds   SMALLINT UNSIGNED  NOT NULL DEFAULT 0,
    members_only        TINYINT(1)         NOT NULL DEFAULT 0,
    is_archived         TINYINT(1)         NOT NULL DEFAULT 0,
    created_at          DATETIME           NOT NULL,
    updated_at          DATETIME           NOT NULL,
    CONSTRAINT fk_cr_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE chat_messages
    ADD COLUMN chat_user_id     INT UNSIGNED NULL AFTER ip_hash,
    ADD COLUMN room_id          INT UNSIGNED NULL AFTER chat_user_id,
    ADD COLUMN parent_id        INT UNSIGNED NULL AFTER room_id,
    ADD COLUMN is_pinned        TINYINT(1)   NOT NULL DEFAULT 0 AFTER parent_id,
    ADD COLUMN is_highlighted   TINYINT(1)   NOT NULL DEFAULT 0 AFTER is_pinned,
    ADD COLUMN highlight_color  VARCHAR(7)   NULL AFTER is_highlighted,
    ADD COLUMN highlight_amount DECIMAL(8,2) NULL AFTER highlight_color,
    ADD COLUMN deleted_at       DATETIME     NULL AFTER highlight_amount,
    ADD COLUMN deleted_by       INT UNSIGNED NULL AFTER deleted_at;

ALTER TABLE chat_messages
    ADD CONSTRAINT fk_cm_user       FOREIGN KEY (chat_user_id) REFERENCES chat_users(id)    ON DELETE SET NULL,
    ADD CONSTRAINT fk_cm_room       FOREIGN KEY (room_id)      REFERENCES chat_rooms(id)    ON DELETE SET NULL,
    ADD CONSTRAINT fk_cm_parent     FOREIGN KEY (parent_id)    REFERENCES chat_messages(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_cm_deleted_by FOREIGN KEY (deleted_by)   REFERENCES chat_users(id)    ON DELETE SET NULL;

ALTER TABLE chat_messages
    ADD INDEX idx_room_created (room_id, created_at),
    ADD INDEX idx_pinned (is_pinned);

CREATE TABLE IF NOT EXISTS chat_reactions (
    id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    message_id    INT UNSIGNED  NOT NULL,
    chat_user_id  INT UNSIGNED  NOT NULL,
    reaction      VARCHAR(10)   NOT NULL,
    created_at    DATETIME      NOT NULL,
    CONSTRAINT fk_creact_message FOREIGN KEY (message_id)   REFERENCES chat_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_creact_user    FOREIGN KEY (chat_user_id) REFERENCES chat_users(id)    ON DELETE CASCADE,
    UNIQUE KEY uq_reaction (message_id, chat_user_id, reaction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_user_bans (
    id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    chat_user_id  INT UNSIGNED  NOT NULL,
    room_id       INT UNSIGNED  NULL,
    banned_by     INT UNSIGNED  NOT NULL,
    reason        TEXT          NULL,
    expires_at    DATETIME      NULL,
    created_at    DATETIME      NOT NULL,
    CONSTRAINT fk_cub_user      FOREIGN KEY (chat_user_id) REFERENCES chat_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_cub_room      FOREIGN KEY (room_id)      REFERENCES chat_rooms(id) ON DELETE SET NULL,
    CONSTRAINT fk_cub_banned_by FOREIGN KEY (banned_by)    REFERENCES chat_users(id) ON DELETE CASCADE,
    INDEX idx_cub_user_room (chat_user_id, room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE chat_moderation_logs
    ADD COLUMN target_user_id INT UNSIGNED NULL AFTER moderator_id;

ALTER TABLE chat_moderation_logs
    MODIFY COLUMN action ENUM(
        'approved','flagged','blocked',
        'deleted','restored',
        'pinned','unpinned',
        'hidden','unhidden',
        'highlighted',
        'muted_user','unmuted_user',
        'banned_user','unbanned_user',
        'strike_added'
    ) NOT NULL;

ALTER TABLE chat_moderation_logs
    ADD CONSTRAINT fk_cml_target_user FOREIGN KEY (target_user_id) REFERENCES chat_users(id) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;

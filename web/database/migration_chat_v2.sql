-- ============================================================
-- PTMD Migration — Chat System v2
-- Run ONCE on existing installs that already have chat tables.
-- Fresh installs: schema.sql already includes these tables.
-- New tables use CREATE TABLE IF NOT EXISTS (safe to re-run).
-- ALTER TABLE will fail with "Duplicate column name" if already applied (safe to ignore).
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- chat_messages: hidden state (soft-hide, separate from delete)
ALTER TABLE chat_messages
    ADD COLUMN hidden_at   DATETIME     NULL AFTER deleted_by,
    ADD COLUMN hidden_by   INT UNSIGNED NULL AFTER hidden_at,
    ADD COLUMN hide_reason VARCHAR(255) NULL AFTER hidden_by;

ALTER TABLE chat_messages
    ADD INDEX idx_cm_hidden (hidden_at);

ALTER TABLE chat_messages
    ADD CONSTRAINT fk_cm_hidden_by FOREIGN KEY (hidden_by) REFERENCES chat_users(id) ON DELETE SET NULL;

-- chat_users: trust / strike metadata
ALTER TABLE chat_users
    ADD COLUMN strike_count   TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER badge_label,
    ADD COLUMN trust_level    TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER strike_count,
    ADD COLUMN last_strike_at DATETIME         NULL            AFTER trust_level;

-- chat_rooms: per-room feature flags
ALTER TABLE chat_rooms
    ADD COLUMN reaction_policy   ENUM('all','registered','disabled') NOT NULL DEFAULT 'all' AFTER members_only,
    ADD COLUMN trivia_enabled    TINYINT(1) NOT NULL DEFAULT 0 AFTER reaction_policy,
    ADD COLUMN donations_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER trivia_enabled;

-- Trivia questions bank
CREATE TABLE IF NOT EXISTS chat_trivia_questions (
    id         INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    question   TEXT          NOT NULL,
    answer_a   VARCHAR(255)  NOT NULL,
    answer_b   VARCHAR(255)  NOT NULL,
    answer_c   VARCHAR(255)  NOT NULL,
    answer_d   VARCHAR(255)  NOT NULL,
    correct    ENUM('a','b','c','d') NOT NULL,
    category   VARCHAR(80)   NOT NULL DEFAULT 'general',
    difficulty ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium',
    is_active  TINYINT(1)    NOT NULL DEFAULT 1,
    created_at DATETIME      NOT NULL,
    updated_at DATETIME      NOT NULL,
    INDEX idx_ctq_category (category),
    INDEX idx_ctq_active   (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trivia sessions (one active per room at a time)
CREATE TABLE IF NOT EXISTS chat_trivia_sessions (
    id             INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    room_id        INT UNSIGNED  NOT NULL,
    question_id    INT UNSIGNED  NOT NULL,
    started_by     INT UNSIGNED  NULL,
    status         ENUM('active','closed','expired') NOT NULL DEFAULT 'active',
    closes_at      DATETIME      NOT NULL,
    winner_user_id INT UNSIGNED  NULL,
    created_at     DATETIME      NOT NULL,
    CONSTRAINT fk_cts_room     FOREIGN KEY (room_id)        REFERENCES chat_rooms(id)            ON DELETE CASCADE,
    CONSTRAINT fk_cts_question FOREIGN KEY (question_id)    REFERENCES chat_trivia_questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_cts_started  FOREIGN KEY (started_by)     REFERENCES chat_users(id)            ON DELETE SET NULL,
    CONSTRAINT fk_cts_winner   FOREIGN KEY (winner_user_id) REFERENCES chat_users(id)            ON DELETE SET NULL,
    INDEX idx_cts_room_status (room_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trivia answers (one per user per session)
CREATE TABLE IF NOT EXISTS chat_trivia_answers (
    id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    session_id   INT UNSIGNED  NOT NULL,
    chat_user_id INT UNSIGNED  NOT NULL,
    answer       ENUM('a','b','c','d') NOT NULL,
    is_correct   TINYINT(1)    NOT NULL DEFAULT 0,
    answered_at  DATETIME      NOT NULL,
    UNIQUE KEY uq_cta (session_id, chat_user_id),
    CONSTRAINT fk_cta_session FOREIGN KEY (session_id)   REFERENCES chat_trivia_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_cta_user    FOREIGN KEY (chat_user_id) REFERENCES chat_users(id)           ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Donation intent log (link-redirect tracking only — no payment processing)
CREATE TABLE IF NOT EXISTS chat_donations (
    id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    chat_user_id INT UNSIGNED  NULL,
    display_name VARCHAR(80)   NULL,
    platform     ENUM('paypal','venmo','cashapp') NOT NULL,
    message      TEXT          NULL,
    room_id      INT UNSIGNED  NULL,
    ip_hash      VARCHAR(64)   NULL,
    created_at   DATETIME      NOT NULL,
    CONSTRAINT fk_cd_user FOREIGN KEY (chat_user_id) REFERENCES chat_users(id) ON DELETE SET NULL,
    CONSTRAINT fk_cd_room FOREIGN KEY (room_id)      REFERENCES chat_rooms(id) ON DELETE SET NULL,
    INDEX idx_cd_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Donation site settings
INSERT INTO site_settings (setting_key, setting_value, setting_type, label, group_name, updated_at) VALUES
    ('chat_paypal_me',        '',                                   'string', 'PayPal.me Username (no URL prefix)',  'chat', NOW()),
    ('chat_venmo_handle',     '',                                   'string', 'Venmo Handle (without @)',           'chat', NOW()),
    ('chat_cashapp_handle',   '',                                   'string', 'CashApp $Cashtag (without $)',       'chat', NOW()),
    ('chat_donation_message', 'Help keep the investigation going!', 'string', 'Donation CTA Message',              'chat', NOW()),
    ('chat_donation_goal',    '',                                   'string', 'Donation Goal Label (optional)',     'chat', NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Starter trivia questions
INSERT IGNORE INTO chat_trivia_questions
    (question, answer_a, answer_b, answer_c, answer_d, correct, category, difficulty, is_active, created_at, updated_at)
VALUES
('What does FOIA stand for?',
 'Freedom of Information Act','Federal Office of Internal Audits','Fund of Independent Agencies','Federal Open Inquiry Act',
 'a','journalism','easy',1,NOW(),NOW()),
('Which U.S. branch can declare laws unconstitutional?',
 'Congress','The President','The Supreme Court','The Treasury',
 'c','civics','easy',1,NOW(),NOW()),
('What is a "whistleblower"?',
 'A sports referee','Someone who reports internal misconduct','An investigative journalist','An anonymous foreign spy',
 'b','journalism','easy',1,NOW(),NOW()),
('"Quid pro quo" in a political context means:',
 'Something for nothing','A mutual exchange of favors','Innocent until proven guilty','A secret government deal',
 'b','civics','medium',1,NOW(),NOW()),
('What is "redacting" a document?',
 'Publishing it publicly','Translating it','Blacking out sensitive info before release','Filing it in a government archive',
 'c','journalism','easy',1,NOW(),NOW()),
('Which U.S. amendment protects freedom of the press?',
 'First Amendment','Second Amendment','Fourth Amendment','Sixth Amendment',
 'a','civics','easy',1,NOW(),NOW()),
('What is a "conflict of interest"?',
 'A disagreement between officials','Personal interests improperly influencing professional duties','A criminal charge','A lawsuit between companies',
 'b','civics','medium',1,NOW(),NOW()),
('"Deep Throat" in journalism refers to:',
 'A CIA operation','The secret Watergate source','A Vietnam operation','A congressional investigation code',
 'b','journalism','medium',1,NOW(),NOW()),
('Which president resigned due to Watergate?',
 'Lyndon B. Johnson','Gerald Ford','Richard Nixon','Jimmy Carter',
 'c','history','easy',1,NOW(),NOW()),
('What is "gerrymandering"?',
 'A form of election fraud','Manipulating district boundaries for political advantage','Financial corruption','Vote buying',
 'b','civics','medium',1,NOW(),NOW()),
('In journalism, "off the record" means:',
 'Information that cannot be published','Information needing verification','A quote used without attribution','A false story',
 'a','journalism','easy',1,NOW(),NOW()),
('What is a "SLAPP suit"?',
 'A defamation suit against a public figure','A lawsuit used to silence critics or journalists','An emergency injunction','A class action lawsuit',
 'b','journalism','hard',1,NOW(),NOW());

SET FOREIGN_KEY_CHECKS = 1;

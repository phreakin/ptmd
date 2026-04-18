-- ============================================================
-- Migration: Hooks tables
-- Adds: hooks, hook_variants, hook_performance
--
-- hooks            — scored opening hooks for cases / clips
-- hook_variants    — format-specific renditions of a hook
-- hook_performance — live platform metrics snapshotted per hook
--
-- Safe to re-run (IF NOT EXISTS / idempotent).
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- hooks — primary hook record with multi-dimensional scoring
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hooks (
    id                      INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    case_id                 INT UNSIGNED    NULL,
    clip_id                 INT UNSIGNED    NULL,
    platform                VARCHAR(80)     NULL,
    hook_text               TEXT            NOT NULL,
    short_hook_text         VARCHAR(280)    NULL,                           -- tweet-length variant
    hook_type               ENUM(
                                'shock_contradiction','hidden_truth','accusation',
                                'irony','data_alarm','curiosity','authority_conflict',
                                'timeline_twist','follow_money','doc_mystery',
                                'cultural_comparison','humor_skepticism',
                                'makes_no_sense','not_telling_you',
                                'pattern_is_story','custom'
                            ) NOT NULL DEFAULT 'curiosity',
    hook_angle              VARCHAR(120)    NULL,
    intended_cohort         VARCHAR(80)     NULL DEFAULT 'general',
    trend_alignment_score   DECIMAL(5,2)    NOT NULL DEFAULT 0,
    novelty_score           DECIMAL(5,2)    NOT NULL DEFAULT 0,
    clarity_score           DECIMAL(5,2)    NOT NULL DEFAULT 0,
    curiosity_score         DECIMAL(5,2)    NOT NULL DEFAULT 0,
    tension_score           DECIMAL(5,2)    NOT NULL DEFAULT 0,
    expected_retention_score DECIMAL(5,2)   NOT NULL DEFAULT 0,
    confidence_score        DECIMAL(5,2)    NOT NULL DEFAULT 0,
    explanation             TEXT            NULL,
    ai_generation_id        INT UNSIGNED    NULL,
    status                  ENUM('draft','approved','rejected','used','archived') NOT NULL DEFAULT 'draft',
    approved_by             INT UNSIGNED    NULL,
    approved_at             DATETIME        NULL,
    created_by              INT UNSIGNED    NULL,
    created_at              DATETIME        NOT NULL,
    updated_at              DATETIME        NOT NULL,
    CONSTRAINT fk_h_case      FOREIGN KEY (case_id)          REFERENCES cases(id)          ON DELETE SET NULL,
    CONSTRAINT fk_h_clip      FOREIGN KEY (clip_id)          REFERENCES video_clips(id)    ON DELETE SET NULL,
    CONSTRAINT fk_h_aigen     FOREIGN KEY (ai_generation_id) REFERENCES ai_generations(id) ON DELETE SET NULL,
    CONSTRAINT fk_h_approver  FOREIGN KEY (approved_by)      REFERENCES users(id)          ON DELETE SET NULL,
    CONSTRAINT fk_h_creator   FOREIGN KEY (created_by)       REFERENCES users(id)          ON DELETE SET NULL,
    INDEX idx_h_case     (case_id),
    INDEX idx_h_platform (platform),
    INDEX idx_h_type     (hook_type),
    INDEX idx_h_status   (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- hook_variants — format-specific renditions of a hook
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hook_variants (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    hook_id         INT UNSIGNED    NOT NULL,
    variant_type    ENUM(
                        'longform_opener','shortform_opener','caption_opener',
                        'headline_opener','thumbnail_text','thread_opener','teaser_intro'
                    ) NOT NULL DEFAULT 'shortform_opener',
    variant_text    TEXT            NOT NULL,
    score           DECIMAL(5,2)    NOT NULL DEFAULT 0,
    selected        TINYINT(1)      NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL,
    CONSTRAINT fk_hv_hook FOREIGN KEY (hook_id) REFERENCES hooks(id) ON DELETE CASCADE,
    INDEX idx_hv_hook (hook_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- hook_performance — snapshotted platform metrics per hook
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hook_performance (
    id                  INT UNSIGNED        AUTO_INCREMENT PRIMARY KEY,
    hook_id             INT UNSIGNED        NOT NULL,
    queue_id            INT UNSIGNED        NULL,                           -- social_post_queue ref
    platform            VARCHAR(80)         NOT NULL,
    views               BIGINT UNSIGNED     NOT NULL DEFAULT 0,
    likes               BIGINT UNSIGNED     NOT NULL DEFAULT 0,
    comments            BIGINT UNSIGNED     NOT NULL DEFAULT 0,
    shares              BIGINT UNSIGNED     NOT NULL DEFAULT 0,
    watch_time_sec      BIGINT UNSIGNED     NOT NULL DEFAULT 0,
    completion_rate     DECIMAL(5,2)        NULL,                           -- % of viewers who finished
    ctr                 DECIMAL(5,2)        NULL,                           -- click-through rate %
    retention_score     DECIMAL(5,2)        NULL,
    engagement_score    DECIMAL(8,4)        NULL,
    snapped_at          DATETIME            NOT NULL,
    CONSTRAINT fk_hp_hook  FOREIGN KEY (hook_id)  REFERENCES hooks(id)             ON DELETE CASCADE,
    CONSTRAINT fk_hp_queue FOREIGN KEY (queue_id) REFERENCES social_post_queue(id) ON DELETE SET NULL,
    INDEX idx_hp_hook     (hook_id),
    INDEX idx_hp_platform (platform, snapped_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

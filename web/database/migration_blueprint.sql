-- ============================================================
-- PTMD Migration: video_blueprints + posting_blueprints
-- Requires existing PTMD schema.sql
-- Assumes an assets table already exists for reusable hooks,
-- overlays, subtitles, thumbnails, scripts, etc.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- VIDEO BLUEPRINTS
-- Master planning record for a video/clip concept
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS video_blueprints
(
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id                 INT UNSIGNED                     NULL,
    source_clip_id          INT UNSIGNED                     NULL,
    parent_blueprint_id     INT UNSIGNED                     NULL,

    blueprint_name          VARCHAR(255)                     NOT NULL,
    slug                    VARCHAR(255)                     NULL UNIQUE,

    topic                   VARCHAR(120)                     NULL,
    angle                   VARCHAR(255)                     NULL,
    tone                    VARCHAR(255)                     NULL, -- dark,skeptical,funny,investigative
    format_type             ENUM (
        'teaser',
        'clip',
        'reaction',
        'follow_up',
        'case_chat',
        'full_documentary',
        'cold_open',
        'other'
        )                                                    NOT NULL DEFAULT 'clip',

    target_platform         VARCHAR(80)                      NULL,
    aspect_ratio            ENUM ('9:16','16:9','1:1','4:5') NOT NULL DEFAULT '9:16',
    target_duration_sec     DECIMAL(8, 2)                    NULL,

    hook_text               TEXT                             NULL,
    setup_text              TEXT                             NULL,
    payoff_text             TEXT                             NULL,
    loop_text               TEXT                             NULL,
    cta_text                TEXT                             NULL,

    source_video_path       VARCHAR(255)                     NULL,
    output_video_path       VARCHAR(255)                     NULL,
    subtitle_file_path      VARCHAR(255)                     NULL,

    render_profile          VARCHAR(120)                     NULL, -- shorts_fast, reels_standard, youtube_teaser, etc.
    render_settings_json    JSON                             NULL,
    validation_json         JSON                             NULL,
    blueprint_json          JSON                             NULL,
    notes                   TEXT                             NULL,

    scoring_formula_version VARCHAR(50)                      NULL,
    blueprint_score         DECIMAL(8, 2)                    NULL,
    estimated_performance   DECIMAL(8, 2)                    NULL,

    status                  ENUM (
        'draft',
        'ready',
        'approved',
        'rendering',
        'rendered',
        'failed',
        'archived'
        )                                                    NOT NULL DEFAULT 'draft',

    created_by              INT UNSIGNED                     NULL,
    approved_by             INT UNSIGNED                     NULL,
    approved_at             DATETIME                         NULL,
    created_at              DATETIME                         NOT NULL,
    updated_at              DATETIME                         NOT NULL,

    CONSTRAINT fk_vb_case
        FOREIGN KEY (case_id) REFERENCES cases (id) ON DELETE SET NULL,
    CONSTRAINT fk_vb_clip
        FOREIGN KEY (source_clip_id) REFERENCES video_clips (id) ON DELETE SET NULL,
    CONSTRAINT fk_vb_parent
        FOREIGN KEY (parent_blueprint_id) REFERENCES video_blueprints (id) ON DELETE SET NULL,
    CONSTRAINT fk_vb_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_vb_approved_by
        FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,

    INDEX idx_vb_case_status (case_id, status),
    INDEX idx_vb_topic_format (topic, format_type),
    INDEX idx_vb_platform_status (target_platform, status),
    INDEX idx_vb_score (blueprint_score),
    INDEX idx_vb_created (created_at)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- VIDEO BLUEPRINT SEGMENTS
-- Ordered structure for hook / setup / payoff / loop / CTA
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS video_blueprint_segments
(
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    video_blueprint_id    INT UNSIGNED                                     NOT NULL,

    phase                 ENUM (
        'hook',
        'setup',
        'payoff',
        'loop',
        'cta',
        'transition',
        'other'
        )                                                                  NOT NULL,

    segment_order         SMALLINT UNSIGNED                                NOT NULL DEFAULT 1,
    asset_id              INT UNSIGNED                                     NULL,

    segment_label         VARCHAR(255)                                     NULL,
    text_content          TEXT                                             NULL,
    voiceover_text        TEXT                                             NULL,

    planned_start_sec     DECIMAL(8, 2)                                    NULL,
    planned_end_sec       DECIMAL(8, 2)                                    NULL,
    planned_duration_sec  DECIMAL(8, 2)                                    NULL,

    source_in_sec         DECIMAL(8, 2)                                    NULL,
    source_out_sec        DECIMAL(8, 2)                                    NULL,

    subtitle_mode         ENUM ('none','burned','external')                NOT NULL DEFAULT 'burned',
    overlay_mode          ENUM ('none','lower_third','full_frame','stamp') NOT NULL DEFAULT 'none',

    segment_settings_json JSON                                             NULL,
    created_at            DATETIME                                         NOT NULL,
    updated_at            DATETIME                                         NOT NULL,

    CONSTRAINT fk_vbs_blueprint
        FOREIGN KEY (video_blueprint_id) REFERENCES video_blueprints (id) ON DELETE CASCADE,
    CONSTRAINT fk_vbs_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE SET NULL,

    UNIQUE KEY uq_vbs_blueprint_order (video_blueprint_id, segment_order),
    INDEX idx_vbs_phase (video_blueprint_id, phase),
    INDEX idx_vbs_asset (asset_id)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- VIDEO BLUEPRINT ASSETS
-- Asset attachments not tied to a single timeline segment
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS video_blueprint_assets
(
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    video_blueprint_id INT UNSIGNED      NOT NULL,
    asset_id           INT UNSIGNED      NOT NULL,

    asset_role         ENUM (
        'hook',
        'setup',
        'payoff',
        'loop',
        'caption',
        'subtitle',
        'overlay',
        'thumbnail',
        'audio',
        'music_bed',
        'watermark',
        'intro',
        'outro',
        'other'
        )                                NOT NULL,

    is_primary         TINYINT(1)        NOT NULL DEFAULT 0,
    render_order       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    usage_notes        VARCHAR(255)      NULL,
    settings_json      JSON              NULL,

    created_at         DATETIME          NOT NULL,
    updated_at         DATETIME          NOT NULL,

    CONSTRAINT fk_vba_blueprint
        FOREIGN KEY (video_blueprint_id) REFERENCES video_blueprints (id) ON DELETE CASCADE,
    CONSTRAINT fk_vba_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE,

    UNIQUE KEY uq_vba_blueprint_role_asset (video_blueprint_id, asset_role, asset_id),
    INDEX idx_vba_role (video_blueprint_id, asset_role),
    INDEX idx_vba_asset (asset_id)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- POSTING BLUEPRINTS
-- Campaign-level reusable social posting plan
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS posting_blueprints
(
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    video_blueprint_id     INT UNSIGNED NULL,
    case_id                INT UNSIGNED NULL,

    blueprint_name         VARCHAR(255) NOT NULL,
    slug                   VARCHAR(255) NULL UNIQUE,

    campaign_type          ENUM (
        'launch',
        'teaser_cycle',
        'weekly_case',
        'clip_distribution',
        'reaction_cycle',
        'evergreen',
        'other'
        )                               NOT NULL DEFAULT 'clip_distribution',

    objective              VARCHAR(255) NULL, -- awareness, traffic, subscribers, comments, shares
    audience_profile       VARCHAR(255) NULL,
    topic                  VARCHAR(120) NULL,
    tone                   VARCHAR(255) NULL,

    primary_cta            VARCHAR(255) NULL,
    secondary_cta          VARCHAR(255) NULL,
    hashtags               VARCHAR(500) NULL,
    link_url               VARCHAR(500) NULL,

    caption_template       TEXT         NULL,
    thread_template        MEDIUMTEXT   NULL,
    comments_seed_template TEXT         NULL,

    schedule_mode          ENUM (
        'manual',
        'immediate',
        'best_window',
        'cadence_based'
        )                               NOT NULL DEFAULT 'cadence_based',

    timezone               VARCHAR(100) NOT NULL DEFAULT 'America/Phoenix',
    posting_rules_json     JSON         NULL,
    blueprint_json         JSON         NULL,
    notes                  TEXT         NULL,

    status                 ENUM (
        'draft',
        'ready',
        'approved',
        'active',
        'paused',
        'archived'
        )                               NOT NULL DEFAULT 'draft',

    created_by             INT UNSIGNED NULL,
    approved_by            INT UNSIGNED NULL,
    approved_at            DATETIME     NULL,
    created_at             DATETIME     NOT NULL,
    updated_at             DATETIME     NOT NULL,

    CONSTRAINT fk_pb_video_blueprint
        FOREIGN KEY (video_blueprint_id) REFERENCES video_blueprints (id) ON DELETE SET NULL,
    CONSTRAINT fk_pb_case
        FOREIGN KEY (case_id) REFERENCES cases (id) ON DELETE SET NULL,
    CONSTRAINT fk_pb_created_by
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_pb_approved_by
        FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,

    INDEX idx_pb_case_status (case_id, status),
    INDEX idx_pb_campaign_status (campaign_type, status),
    INDEX idx_pb_video_blueprint (video_blueprint_id),
    INDEX idx_pb_created (created_at)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- POSTING BLUEPRINT PLATFORMS
-- Per-platform instructions for a posting blueprint
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS posting_blueprint_platforms
(
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    posting_blueprint_id   INT UNSIGNED      NOT NULL,
    posting_site_id        INT UNSIGNED      NOT NULL,

    content_type           VARCHAR(80)       NOT NULL, -- teaser, clip, full documentary, thread, launch post
    post_format            ENUM (
        'video',
        'image',
        'thread',
        'text',
        'link',
        'mixed'
        )                                    NOT NULL DEFAULT 'video',

    title_template         VARCHAR(255)      NULL,
    caption_template       TEXT              NULL,
    hashtag_template       VARCHAR(500)      NULL,
    first_comment_template TEXT              NULL,
    thumbnail_asset_id     INT UNSIGNED      NULL,
    overlay_asset_id       INT UNSIGNED      NULL,

    use_platform_defaults  TINYINT(1)        NOT NULL DEFAULT 1,
    auto_queue             TINYINT(1)        NOT NULL DEFAULT 1,
    is_enabled             TINYINT(1)        NOT NULL DEFAULT 1,

    preferred_day_of_week  VARCHAR(20)       NULL,
    preferred_post_time    TIME              NULL,
    fallback_delay_minutes INT UNSIGNED      NOT NULL DEFAULT 0,

    posting_priority       SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    max_retries            TINYINT UNSIGNED  NOT NULL DEFAULT 3,

    platform_rules_json    JSON              NULL,
    created_at             DATETIME          NOT NULL,
    updated_at             DATETIME          NOT NULL,

    CONSTRAINT fk_pbp_blueprint
        FOREIGN KEY (posting_blueprint_id) REFERENCES posting_blueprints (id) ON DELETE CASCADE,
    CONSTRAINT fk_pbp_site
        FOREIGN KEY (posting_site_id) REFERENCES posting_sites (id) ON DELETE CASCADE,
    CONSTRAINT fk_pbp_thumbnail_asset
        FOREIGN KEY (thumbnail_asset_id) REFERENCES assets (id) ON DELETE SET NULL,
    CONSTRAINT fk_pbp_overlay_asset
        FOREIGN KEY (overlay_asset_id) REFERENCES assets (id) ON DELETE SET NULL,

    UNIQUE KEY uq_pbp_blueprint_site (posting_blueprint_id, posting_site_id),
    INDEX idx_pbp_enabled_priority (is_enabled, posting_priority),
    INDEX idx_pbp_content_type (content_type)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- POSTING BLUEPRINT RUNS
-- Materialized execution record for a specific post instance
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS posting_blueprint_runs
(
    id                            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    posting_blueprint_id          INT UNSIGNED NOT NULL,
    posting_blueprint_platform_id INT UNSIGNED NOT NULL,

    case_id                       INT UNSIGNED NULL,
    clip_id                       INT UNSIGNED NULL,
    video_blueprint_id            INT UNSIGNED NULL,

    queue_id                      INT UNSIGNED NULL,
    run_label                     VARCHAR(255) NULL,

    resolved_title                VARCHAR(255) NULL,
    resolved_caption              TEXT         NULL,
    resolved_hashtags             VARCHAR(500) NULL,
    resolved_asset_path           VARCHAR(255) NULL,
    resolved_link_url             VARCHAR(500) NULL,

    scheduled_for                 DATETIME     NULL,
    posted_at                     DATETIME     NULL,

    run_status                    ENUM (
        'draft',
        'materialized',
        'queued',
        'scheduled',
        'posted',
        'failed',
        'canceled'
        )                                      NOT NULL DEFAULT 'draft',

    request_payload_json          JSON         NULL,
    response_payload_json         JSON         NULL,
    error_message                 TEXT         NULL,

    created_at                    DATETIME     NOT NULL,
    updated_at                    DATETIME     NOT NULL,

    CONSTRAINT fk_pbr_blueprint
        FOREIGN KEY (posting_blueprint_id) REFERENCES posting_blueprints (id) ON DELETE CASCADE,
    CONSTRAINT fk_pbr_blueprint_platform
        FOREIGN KEY (posting_blueprint_platform_id) REFERENCES posting_blueprint_platforms (id) ON DELETE CASCADE,
    CONSTRAINT fk_pbr_case
        FOREIGN KEY (case_id) REFERENCES cases (id) ON DELETE SET NULL,
    CONSTRAINT fk_pbr_clip
        FOREIGN KEY (clip_id) REFERENCES video_clips (id) ON DELETE SET NULL,
    CONSTRAINT fk_pbr_video_blueprint
        FOREIGN KEY (video_blueprint_id) REFERENCES video_blueprints (id) ON DELETE SET NULL,
    CONSTRAINT fk_pbr_queue
        FOREIGN KEY (queue_id) REFERENCES social_post_queue (id) ON DELETE SET NULL,

    INDEX idx_pbr_status_scheduled (run_status, scheduled_for),
    INDEX idx_pbr_queue (queue_id),
    INDEX idx_pbr_clip (clip_id),
    INDEX idx_pbr_case (case_id)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
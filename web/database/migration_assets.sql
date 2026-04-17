CREATE TABLE IF NOT EXISTS assets
(
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Core identity
    asset_type        ENUM (
        'hook',
        'one_liner',
        'script',
        'subtitle',
        'image',
        'overlay',
        'thumbnail',
        'video_clip',
        'audio',
        'template',
        'other'
        )                           NOT NULL,

    title             VARCHAR(255)  NULL,
    slug              VARCHAR(255)  NULL UNIQUE,

    -- Content
    content_text      MEDIUMTEXT    NULL, -- hooks, scripts, captions, etc.
    content_json      JSON          NULL, -- structured formats (LRC, SRT, configs)
    file_path         VARCHAR(255)  NULL, -- for images, overlays, clips, etc.

    -- Classification
    tone              VARCHAR(100)  NULL, -- dark, funny, investigative, sarcastic
    category          VARCHAR(100)  NULL, -- intro, hook, outro, transition, etc.
    tags_json         JSON          NULL,

    -- Performance tracking
    usage_count       INT UNSIGNED                       DEFAULT 0,
    last_used_at      DATETIME      NULL,
    performance_score DECIMAL(5, 2) NULL, -- custom scoring (CTR, retention, etc.)
    engagement_score  DECIMAL(5, 2) NULL,

    -- Status
    status            ENUM ('draft','active','archived') DEFAULT 'active',
    is_favorite       TINYINT(1)                         DEFAULT 0,

    -- Ownership
    created_by        INT UNSIGNED  NULL,

    created_at        DATETIME      NOT NULL,
    updated_at        DATETIME      NOT NULL,

    INDEX idx_type (asset_type),
    INDEX idx_status (status),
    INDEX idx_tone (tone),
    INDEX idx_performance (performance_score),
    FULLTEXT INDEX idx_content_text (content_text),

    CONSTRAINT fk_assets_user
        FOREIGN KEY (created_by) REFERENCES users (id)
            ON DELETE SET NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_usage_logs
(
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id              INT UNSIGNED                                                                  NOT NULL,
    case_id               INT UNSIGNED                                                                  NULL,
    clip_id               INT UNSIGNED                                                                  NULL,
    platform              VARCHAR(80)                                                                   NULL,
    topic                 VARCHAR(120)                                                                  NULL,
    used_as               ENUM ('hook','setup','payoff','loop','caption','overlay','subtitle','script') NOT NULL,
    views                 INT UNSIGNED                                                                  NULL DEFAULT 0,
    likes                 INT UNSIGNED                                                                  NULL DEFAULT 0,
    comments_count        INT UNSIGNED                                                                  NULL DEFAULT 0,
    shares                INT UNSIGNED                                                                  NULL DEFAULT 0,
    watch_time_sec        DECIMAL(10, 2)                                                                NULL,
    avg_view_duration_sec DECIMAL(10, 2)                                                                NULL,
    completion_rate       DECIMAL(5, 2)                                                                 NULL,
    ctr                   DECIMAL(5, 2)                                                                 NULL,
    engagement_score      DECIMAL(8, 2)                                                                 NULL,
    performance_score     DECIMAL(8, 2)                                                                 NULL,
    created_at            DATETIME                                                                      NOT NULL,
    updated_at            DATETIME                                                                      NOT NULL,

    CONSTRAINT fk_aul_asset FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE,
    CONSTRAINT fk_aul_case FOREIGN KEY (case_id) REFERENCES cases (id) ON DELETE SET NULL,
    CONSTRAINT fk_aul_clip FOREIGN KEY (clip_id) REFERENCES video_clips (id) ON DELETE SET NULL,

    INDEX idx_asset_topic (asset_id, topic),
    INDEX idx_topic_usedas (topic, used_as),
    INDEX idx_platform_usedas (platform, used_as),
    INDEX idx_perf (performance_score)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

INSERT INTO assets (asset_type, content_text, tone, category, created_at, updated_at)
VALUES ('hook',
        'This doesn’t look illegal… but it should.',
        'dark, investigative',
        'intro',
        NOW(), NOW());

INSERT INTO assets (asset_type, content_text, tone, category, created_at, updated_at)
VALUES ('one_liner',
        'Technically legal. Morally… that’s a different department.',
        'dark, sarcastic',
        'punchline',
        NOW(), NOW());

INSERT INTO assets (asset_type, content_json, category, created_at, updated_at)
VALUES ('subtitle',
        JSON_OBJECT(
                'format', 'srt',
                'content', '1\n00:00:00,000 --> 00:00:02,000\nThis doesn’t look illegal...\n'
        ),
        'subtitle',
        NOW(), NOW());

INSERT INTO assets (asset_type, file_path, category, created_at, updated_at)
VALUES ('overlay',
        '/assets/brand/overlays/ptmd_overlay_lower_third.png',
        'branding',
        NOW(), NOW());

ALTER TABLE assets
    ADD COLUMN topic        VARCHAR(120)                                                                       NULL AFTER category,
    ADD COLUMN target_phase ENUM ('hook','setup','payoff','loop','caption','overlay','subtitle','full_script') NULL AFTER topic,
    ADD COLUMN source_notes TEXT                                                                               NULL AFTER content_json,
    ADD COLUMN approved     TINYINT(1)                                                                         NOT NULL DEFAULT 1 AFTER is_favorite;

SELECT
    a.id,
    a.title,
    a.content_text,
    a.tone,
    a.category,
    a.topic,
    AVG(aul.performance_score) AS avg_performance,
    COUNT(*) AS usage_instances
FROM assets a
         JOIN asset_usage_logs aul ON a.id = aul.asset_id
WHERE a.asset_type = 'one_liner'
  AND a.approved = 1
  AND a.status = 'active'
  AND a.tone LIKE '%dark%'
GROUP BY a.id, a.title, a.content_text, a.tone, a.category, a.topic
HAVING COUNT(*) >= 3
ORDER BY avg_performance DESC, usage_instances DESC
LIMIT 10;

SELECT
    a.id,
    a.title,
    a.content_text,
    a.topic,
    a.target_phase,
    AVG(aul.performance_score) AS avg_performance,
    AVG(aul.completion_rate) AS avg_completion,
    COUNT(*) AS uses
FROM assets a
         LEFT JOIN asset_usage_logs aul ON a.id = aul.asset_id
WHERE a.asset_type = 'hook'
  AND a.target_phase = 'hook'
  AND a.approved = 1
  AND a.status = 'active'
  AND (
    a.topic = 'corruption'
        OR JSON_CONTAINS(a.tags_json, JSON_QUOTE('corruption'))
    )
GROUP BY a.id, a.title, a.content_text, a.topic, a.target_phase
ORDER BY avg_performance DESC, avg_completion DESC, uses DESC
LIMIT 5;


-- ============================================================
-- PTMD Seed Data  (run after schema.sql)
-- ============================================================

-- Admin user   password = admin123!   (bcrypt hash)
INSERT INTO users (username, email, password_hash, role, created_at, updated_at)
VALUES (
    'admin',
    'admin@papertrailmd.local',
    '$2y$12$S8Y9BBdMNqNiGHN/r6Y8H.04icmFFUDqIZKYu.mEwIDoD7nAXHMU2',
    'admin',
    NOW(), NOW()
) ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Site settings
INSERT INTO site_settings (setting_key, setting_value, setting_type, label, group_name, updated_at) VALUES
('site_name',            'Paper Trail MD',                                          'string', 'Site Name',            'general',  NOW()),
('site_tagline',         'Investigative. Sharp. Cinematic.',                        'string', 'Tagline',              'general',  NOW()),
('site_email',           'papertrailmd@gmail.com',                                  'string', 'Contact Email',        'general',  NOW()),
('site_domain',          'papertrailmd.com',                                        'string', 'Domain',               'general',  NOW()),
('site_description',     'Hard-hitting but funny mini-documentaries on social, cultural, and political stories.', 'string', 'Meta Description', 'general', NOW()),
('hero_headline',        'Truth with Teeth.',                                       'string', 'Hero Headline',        'homepage', NOW()),
('hero_subheadline',     'Investigative mini-docs with cinematic style and satirical precision.', 'string', 'Hero Sub-headline', 'homepage', NOW()),
('hero_cta_text',        'Watch Latest case',                                    'string', 'Hero CTA Text',        'homepage', NOW()),
('home_module_layout',   '["hero","featured","latest","social"]',                   'json',   'Homepage Module Layout','homepage', NOW()),
('social_youtube',       'https://youtube.com/@papertrailmd',                       'string', 'YouTube URL',          'social',   NOW()),
('social_x',             'https://x.com/papertrailmd',                              'string', 'X / Twitter URL',      'social',   NOW()),
('social_instagram',     'https://instagram.com/papertrailmd',                      'string', 'Instagram URL',        'social',   NOW()),
('social_tiktok',        'https://tiktok.com/@papertrailmd',                        'string', 'TikTok URL',           'social',   NOW()),
('social_facebook',      'https://facebook.com/papertrailmd',                       'string', 'Facebook URL',         'social',   NOW()),
('default_timezone',     'America/Phoenix',                                         'string', 'Default Timezone',     'system',   NOW()),
('openai_api_key',       '',                                                        'secret', 'OpenAI API Key',       'ai',       NOW()),
('openai_model',         'gpt-4o-mini',                                             'string', 'OpenAI Model',         'ai',       NOW()),
('watermark_asset_path', '/assets/brand/watermarks/ptmd_watermark.png',            'string', 'Watermark Asset Path', 'brand',    NOW()),
('intro_asset_path',     '/assets/brand/intros/ptmd_intro.mp4',                    'string', 'Intro Asset Path',     'brand',    NOW()),
('default_overlay_path', '/assets/brand/overlays/ptmd_overlay_lower_third.png',   'string', 'Default Overlay',      'brand',    NOW()),
('ffmpeg_path',          'ffmpeg',                                                  'string', 'FFmpeg Binary',        'system',   NOW()),
('ffprobe_path',         'ffprobe',                                                 'string', 'FFprobe Binary',       'system',   NOW()),
('automation_worker_token', 'change-me-worker-token',                               'secret', 'Automation Worker Token','system',  NOW())
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW();

-- Sample cases
INSERT INTO cases (title, slug, excerpt, body, thumbnail_image, featured_image, video_url, duration, status, published_at, created_at, updated_at) VALUES
(
    'City Hall After Dark',
    'city-hall-after-dark',
    'An evidence-first dive into procurement games, public money, and the people who never seem to lose a contract.',
    'Full investigative breakdown of municipal procurement patterns. Sources: city council minutes (2019–2023), FOIA-released emails, contractor registration filings, and interviews with three anonymous city department heads.\n\nThe numbers don''t lie. The contracts do.\n\nOver an 18-month period, a single contractor received 74% of discretionary IT spend without competitive bidding — citing an emergency exemption clause that was renewed 11 consecutive quarters.\n\nThis case traces the chain: who signed off, who benefited, and why the whistleblower got reassigned instead of thanked.',
    '/assets/brand/thumbnails/ptmd_ep1_cityhall.png',
    '/assets/brand/thumbnails/ptmd_ep1_cityhall.png',
    'https://www.youtube.com/embed/dQw4w9WgXcQ',
    '18:22',
    'published',
    NOW() - INTERVAL 14 DAY,
    NOW() - INTERVAL 14 DAY,
    NOW() - INTERVAL 14 DAY
),
(
    'School Board Heat Check',
    'school-board-heat-check',
    'How political theater became curriculum policy warfare — and what the parents caught in the middle actually want.',
    'Six months covering school board meetings in three different districts. What we found: the loudest voices are almost never local parents.\n\nThis case maps the network of outside advocacy groups coordinating public comment campaigns, the national funding sources behind local candidates, and the exhausted teachers stuck in the crossfire.\n\nFunny? Occasionally. Disturbing? Often. Well-sourced? Always.',
    '/assets/brand/thumbnails/ptmd_ep2_schoolboard.png',
    '/assets/brand/thumbnails/ptmd_ep2_schoolboard.png',
    'https://www.youtube.com/embed/dQw4w9WgXcQ',
    '14:06',
    'published',
    NOW() - INTERVAL 7 DAY,
    NOW() - INTERVAL 7 DAY,
    NOW() - INTERVAL 7 DAY
),
(
    'The Permit Maze',
    'the-permit-maze',
    'Who profits when compliance turns into chaos? A follow-the-money look at the permitting industrial complex.',
    'Starting a business in this city requires an average of 23 permits, 14 different city departments, and — if our research is accurate — approximately 400 hours of labor before a single customer walks through the door.\n\nWe went through the process ourselves. Then we talked to the consultants who charge $10,000 to navigate it. Then we found the campaign donation records.\n\nIt''s not a bug. It''s a feature.',
    '/assets/brand/thumbnails/ptmd_ep3_permits.png',
    '/assets/brand/thumbnails/ptmd_ep3_permits.png',
    'https://www.youtube.com/embed/dQw4w9WgXcQ',
    '21:11',
    'published',
    NOW() - INTERVAL 2 DAY,
    NOW() - INTERVAL 2 DAY,
    NOW() - INTERVAL 2 DAY
)
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- case tags
INSERT IGNORE INTO case_tags (name, slug, created_at, updated_at) VALUES
('accountability',    'accountability',    NOW(), NOW()),
('politics',          'politics',          NOW(), NOW()),
('culture',           'culture',           NOW(), NOW()),
('corruption',        'corruption',        NOW(), NOW()),
('education',         'education',         NOW(), NOW()),
('local government',  'local-government',  NOW(), NOW()),
('follow the money',  'follow-the-money',  NOW(), NOW());

-- Tag-case mappings
INSERT IGNORE INTO case_tag_map (case_id, tag_id)
SELECT e.id, t.id FROM cases e
JOIN case_tags t ON t.slug IN ('accountability','corruption','local-government','follow-the-money')
WHERE e.slug = 'city-hall-after-dark';

INSERT IGNORE INTO case_tag_map (case_id, tag_id)
SELECT e.id, t.id FROM cases e
JOIN case_tags t ON t.slug IN ('education','politics','culture')
WHERE e.slug = 'school-board-heat-check';

INSERT IGNORE INTO case_tag_map (case_id, tag_id)
SELECT e.id, t.id FROM cases e
JOIN case_tags t ON t.slug IN ('local-government','corruption','follow-the-money')
WHERE e.slug = 'the-permit-maze';

-- Canonical posting sites (idempotent — keyed on site_key unique index)
INSERT INTO posting_sites (site_key, display_name, is_active, sort_order, created_at, updated_at) VALUES
('youtube',          'YouTube',          1, 10, NOW(), NOW()),
('youtube_shorts',   'YouTube Shorts',   1, 20, NOW(), NOW()),
('tiktok',           'TikTok',           1, 30, NOW(), NOW()),
('instagram_reels',  'Instagram Reels',  1, 40, NOW(), NOW()),
('facebook_reels',   'Facebook Reels',   1, 50, NOW(), NOW()),
('x',                'X',                1, 60, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name),
    is_active    = VALUES(is_active),
    sort_order   = VALUES(sort_order),
    updated_at   = NOW();

-- Default posting options per site (idempotent — keyed on site_id unique index)
INSERT INTO site_posting_options
    (site_id, default_content_type, default_caption_prefix, default_hashtags, default_status, created_at, updated_at)
SELECT
    ps.id,
    v.default_content_type,
    v.default_caption_prefix,
    v.default_hashtags,
    v.default_status,
    NOW(),
    NOW()
FROM posting_sites ps
JOIN (
    SELECT 'youtube'          AS site_key, 'full documentary' AS default_content_type, 'New episode from Paper Trail MD.'   AS default_caption_prefix, '#investigation #documentary' AS default_hashtags, 'queued' AS default_status
    UNION ALL
    SELECT 'youtube_shorts',  'teaser',           'Short cut from Paper Trail MD.',     '#shorts #investigation',      'queued'
    UNION ALL
    SELECT 'tiktok',          'teaser',           'Fresh PTMD clip just dropped.',       '#tiktok #investigation',      'queued'
    UNION ALL
    SELECT 'instagram_reels', 'teaser',           'New reel from Paper Trail MD.',       '#reels #investigation',       'queued'
    UNION ALL
    SELECT 'facebook_reels',  'clip',             'Watch this Paper Trail MD clip.',     '#reels #news',                'queued'
    UNION ALL
    SELECT 'x',               'launch post',      'New post from Paper Trail MD.',       '#news #journalism',           'queued'
) AS v ON v.site_key = ps.site_key
ON DUPLICATE KEY UPDATE
    default_content_type   = VALUES(default_content_type),
    default_caption_prefix = VALUES(default_caption_prefix),
    default_hashtags       = VALUES(default_hashtags),
    default_status         = VALUES(default_status),
    updated_at             = NOW();

-- Default social posting cadence (PTMD recommended schedule — Phoenix time)
INSERT INTO social_post_schedules (platform, content_type, day_of_week, post_time, timezone, is_active, created_at, updated_at) VALUES
('YouTube Shorts',   'teaser',              'Friday',    '17:00:00', 'America/Phoenix', 1, NOW(), NOW()),
('TikTok',           'teaser',              'Saturday',  '18:00:00', 'America/Phoenix', 1, NOW(), NOW()),
('Instagram Reels',  'teaser',              'Saturday',  '18:00:00', 'America/Phoenix', 1, NOW(), NOW()),
('YouTube',          'full documentary',    'Sunday',    '10:00:00', 'America/Phoenix', 1, NOW(), NOW()),
('X',                'launch post',         'Sunday',    '12:30:00', 'America/Phoenix', 1, NOW(), NOW()),
('YouTube Shorts',   'follow-up',           'Sunday',    '19:00:00', 'America/Phoenix', 1, NOW(), NOW()),
('Instagram Reels',  'clip',                'Monday',    '14:00:00', 'America/Phoenix', 1, NOW(), NOW()),
('TikTok',           'clip',                'Tuesday',   '15:00:00', 'America/Phoenix', 1, NOW(), NOW()),
('Facebook Reels',   'clip',                'Wednesday', '13:00:00', 'America/Phoenix', 1, NOW(), NOW()),
('X',                'follow-up clip',      'Thursday',  '12:30:00', 'America/Phoenix', 1, NOW(), NOW());

-- Default platform-level posting preferences
INSERT INTO social_platform_preferences
    (platform, default_content_type, default_caption_prefix, default_hashtags, default_status, is_enabled, created_at, updated_at)
VALUES
    ('YouTube',         'full documentary', 'New case from Paper Trail MD.', '#investigation #documentary', 'queued', 1, NOW(), NOW()),
    ('YouTube Shorts',  'teaser',           'Short cut from Paper Trail MD.',    '#shorts #investigation',      'queued', 1, NOW(), NOW()),
    ('TikTok',          'teaser',           'Fresh PTMD clip just dropped.',      '#tiktok #investigation',      'queued', 1, NOW(), NOW()),
    ('Instagram Reels', 'teaser',           'New reel from Paper Trail MD.',      '#reels #investigation',       'queued', 1, NOW(), NOW()),
    ('Facebook Reels',  'clip',             'Watch this Paper Trail MD clip.',    '#reels #news',                'queued', 1, NOW(), NOW()),
    ('X',               'launch post',      'New post from Paper Trail MD.',      '#news #journalism',           'queued', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    default_content_type   = VALUES(default_content_type),
    default_caption_prefix = VALUES(default_caption_prefix),
    default_hashtags       = VALUES(default_hashtags),
    default_status         = VALUES(default_status),
    is_enabled             = VALUES(is_enabled),
    updated_at             = NOW();

-- Sample queue entry
INSERT INTO social_post_queue (case_id, platform, content_type, caption, asset_path, scheduled_for, status, created_at, updated_at)
SELECT
    e.id,
    'YouTube Shorts',
    'teaser',
    '🔍 City Hall After Dark — Full case drops Sunday. Subscribe so you don''t miss it. #investigation #accountability',
    '/uploads/clips/sample_teaser.mp4',
    NOW() + INTERVAL 2 DAY,
    'queued',
    NOW(),
    NOW()
FROM cases e WHERE e.slug = 'city-hall-after-dark' LIMIT 1;

-- ============================================================
-- Blueprint seed data  (run after schema.sql)
-- ============================================================

-- Video blueprints
INSERT INTO video_blueprints (title, slug, blueprint_type, status, objective, structure_json, brand_notes, target_duration_sec, created_at, updated_at)
VALUES
(
    'Standard Documentary',
    'standard-documentary',
    'documentary',
    'active',
    'Deliver a well-sourced, compelling case video that builds subscriber trust and drives shares.',
    JSON_ARRAY(
        JSON_OBJECT('order', 1, 'label', 'Cold Open',         'notes', '60–90 sec hook: most shocking or funny moment'),
        JSON_OBJECT('order', 2, 'label', 'Context Setup',     'notes', '2–3 min background, cast of characters'),
        JSON_OBJECT('order', 3, 'label', 'Evidence Walkthrough', 'notes', 'Methodical, source-cited breakdown'),
        JSON_OBJECT('order', 4, 'label', 'Pattern / Reveal',  'notes', 'Connect the dots, show the system'),
        JSON_OBJECT('order', 5, 'label', 'Implication',       'notes', 'Why it matters, who benefits'),
        JSON_OBJECT('order', 6, 'label', 'Close + CTA',       'notes', 'Subscribe / share / comment prompt')
    ),
    'Dry wit, precise sourcing, no speculation. PTMD voice = investigative but not angry. Use lower-thirds for sources.',
    1200,
    NOW(), NOW()
),
(
    'Teaser Cut',
    'teaser-cut',
    'teaser',
    'active',
    'Tease the full case without giving away the conclusion. Drive traffic to long-form video.',
    JSON_ARRAY(
        JSON_OBJECT('order', 1, 'label', 'Hook Moment',   'notes', 'Most jaw-dropping 5–10 seconds'),
        JSON_OBJECT('order', 2, 'label', 'Setup',         'notes', 'One sentence of context'),
        JSON_OBJECT('order', 3, 'label', 'Cliffhanger',   'notes', 'Cut before the answer — leave them wanting more'),
        JSON_OBJECT('order', 4, 'label', 'CTA',           'notes', 'Full case link in description / bio')
    ),
    'Fast-paced edit, no dead air. Brand watermark at bottom-right. End card with PTMD logo.',
    180,
    NOW(), NOW()
)
ON DUPLICATE KEY UPDATE
    objective          = VALUES(objective),
    structure_json     = VALUES(structure_json),
    brand_notes        = VALUES(brand_notes),
    target_duration_sec = VALUES(target_duration_sec),
    updated_at         = NOW();

-- Clip blueprints
INSERT INTO clip_blueprints (title, slug, clip_type, status, target_duration_sec, aspect_ratio, platform_targets, structure_json, brand_notes, created_at, updated_at)
VALUES
(
    '30-Second Teaser',
    '30-second-teaser',
    'teaser',
    'active',
    30,
    '9:16',
    JSON_ARRAY('youtube_shorts', 'tiktok', 'instagram_reels'),
    JSON_ARRAY(
        JSON_OBJECT('order', 1, 'label', 'Hook',     'max_sec', 5,  'notes', 'Verbal or visual punch — no intro'),
        JSON_OBJECT('order', 2, 'label', 'Body',     'max_sec', 20, 'notes', 'One key piece of evidence, no conclusion'),
        JSON_OBJECT('order', 3, 'label', 'CTA',      'max_sec', 5,  'notes', 'Link in bio / subscribe')
    ),
    'Vertical 9:16. Captions burned-in or via platform. PTMD watermark visible.',
    NOW(), NOW()
),
(
    '45-Second Reveal Clip',
    '45-second-reveal-clip',
    'reveal',
    'active',
    45,
    '9:16',
    JSON_ARRAY('youtube_shorts', 'tiktok', 'instagram_reels', 'facebook_reels'),
    JSON_ARRAY(
        JSON_OBJECT('order', 1, 'label', 'Setup',    'max_sec', 10, 'notes', 'Pose the question'),
        JSON_OBJECT('order', 2, 'label', 'Evidence', 'max_sec', 25, 'notes', 'One key doc or stat on screen'),
        JSON_OBJECT('order', 3, 'label', 'Reveal',   'max_sec', 5,  'notes', 'Slow-burn delivery of the answer'),
        JSON_OBJECT('order', 4, 'label', 'CTA',      'max_sec', 5,  'notes', 'Full case drops [day]')
    ),
    'Vertical 9:16. B-roll or document zooms only — no talking-head required.',
    NOW(), NOW()
)
ON DUPLICATE KEY UPDATE
    clip_type          = VALUES(clip_type),
    target_duration_sec = VALUES(target_duration_sec),
    aspect_ratio       = VALUES(aspect_ratio),
    platform_targets   = VALUES(platform_targets),
    structure_json     = VALUES(structure_json),
    brand_notes        = VALUES(brand_notes),
    updated_at         = NOW();

-- Posting blueprints (TikTok + YouTube Shorts)
INSERT INTO posting_blueprints (title, slug, site_key, content_type, status, caption_template, required_hashtags, cta_pattern, config_json, created_at, updated_at)
SELECT v.title, v.slug, v.site_key, v.content_type, v.status, v.caption_template, v.required_hashtags, v.cta_pattern, v.config_json, NOW(), NOW()
FROM (
    SELECT
        'TikTok Teaser Post'       AS title,
        'tiktok-teaser-post'       AS slug,
        'tiktok'                   AS site_key,
        'teaser'                   AS content_type,
        'active'                   AS status,
        '🔍 {title}\n\n{body}\n\n{hashtags}'  AS caption_template,
        '#tiktok #investigation #papertrailmd' AS required_hashtags,
        'Follow for the full case 👇'          AS cta_pattern,
        CAST(JSON_OBJECT('char_limit', 2200, 'min_duration_sec', 5, 'max_duration_sec', 60) AS JSON) AS config_json
    UNION ALL
    SELECT
        'YouTube Shorts Post',
        'youtube-shorts-post',
        'youtube_shorts',
        'teaser',
        'active',
        '▶️ {title}\n\n{body}\n\nFull case in the description 👇\n\n{hashtags}',
        '#Shorts #investigation #documentary',
        'Subscribe + full case link below',
        CAST(JSON_OBJECT('char_limit', 5000, 'min_duration_sec', 15, 'max_duration_sec', 60) AS JSON)
) AS v
ON DUPLICATE KEY UPDATE
    content_type      = VALUES(content_type),
    caption_template  = VALUES(caption_template),
    required_hashtags = VALUES(required_hashtags),
    cta_pattern       = VALUES(cta_pattern),
    config_json       = VALUES(config_json),
    updated_at        = NOW();

-- Blueprint schedule rules (linked to the two posting blueprints above)
INSERT INTO blueprint_schedule_rules (posting_blueprint_id, site_key, day_of_week, post_time, timezone, priority, min_gap_hours, max_per_day, is_active, created_at, updated_at)
SELECT pb.id, pb.site_key, r.day_of_week, r.post_time, 'America/Phoenix', r.priority, r.min_gap_hours, r.max_per_day, 1, NOW(), NOW()
FROM posting_blueprints pb
JOIN (
    SELECT 'tiktok-teaser-post'    AS slug, 'Saturday' AS day_of_week, '18:00:00' AS post_time, 1 AS priority, 4 AS min_gap_hours, 1 AS max_per_day
    UNION ALL
    SELECT 'tiktok-teaser-post',            'Tuesday',                 '15:00:00',               2,              4,                1
    UNION ALL
    SELECT 'youtube-shorts-post',           'Friday',                  '17:00:00',               1,              4,                1
    UNION ALL
    SELECT 'youtube-shorts-post',           'Sunday',                  '19:00:00',               2,              4,                1
) AS r ON r.slug = pb.slug
WHERE pb.status = 'active'
ON DUPLICATE KEY UPDATE
    post_time    = VALUES(post_time),
    priority     = VALUES(priority),
    updated_at   = NOW();

-- Default chat room
INSERT INTO chat_rooms (slug, name, description, is_live, slow_mode_seconds, members_only, is_archived, created_at, updated_at)
VALUES ('case-chat', 'Case Chat', 'The main audience dispatch feed. Drop your case notes, questions, and reactions.', 0, 0, 0, 0, NOW(), NOW())
ON DUPLICATE KEY UPDATE name = VALUES(name), updated_at = NOW();

-- Sample chat messages  (room_id resolved below via UPDATE)
INSERT INTO chat_messages (username, message, status, emojis_json, created_at, updated_at) VALUES
('FactCheckFan',        'That procurement timeline is WILD. 🔥 Keep the receipts coming.',                        'approved', JSON_ARRAY('🔥'), NOW() - INTERVAL 3 HOUR,      NOW() - INTERVAL 3 HOUR),
('DocsOrItDidntHappen', 'Can we get the FOIA request list? Would love to dig deeper into case 1.',                'approved', JSON_ARRAY('📄'), NOW() - INTERVAL 2 HOUR,      NOW() - INTERVAL 2 HOUR),
('SkepticMode',         'Love the dry humor but also genuinely horrified. Appreciate the sourcing. 😅',           'approved', JSON_ARRAY('😅'), NOW() - INTERVAL 90 MINUTE,   NOW() - INTERVAL 90 MINUTE),
('CivicNerd99',         'The school board case connects dots I had never considered. Shared everywhere.',          'approved', JSON_ARRAY(),     NOW() - INTERVAL 45 MINUTE,   NOW() - INTERVAL 45 MINUTE),
('PermitSurvivor',      'Case 3 is my whole life as a small business owner. How is this legal?? 😤',             'approved', JSON_ARRAY('😤'), NOW() - INTERVAL 20 MINUTE,   NOW() - INTERVAL 20 MINUTE);

-- Assign legacy messages (room_id IS NULL) to the default case-chat room
UPDATE chat_messages
SET    room_id = (SELECT id FROM chat_rooms WHERE slug = 'case-chat' LIMIT 1)
WHERE  room_id IS NULL;

-- Starter assets (hooks, overlays — idempotent via slug UNIQUE key)
INSERT INTO assets (asset_type, slug, content_text, tone, category, status, approved, created_at, updated_at)
VALUES
    ('hook',     'hook-not-illegal-but-should',
     'This doesn't look illegal… but it should.',
     'dark, investigative', 'intro', 'active', 1, NOW(), NOW()),
    ('one_liner','one-liner-technically-legal',
     'Technically legal. Morally… that's a different department.',
     'dark, sarcastic', 'punchline', 'active', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

INSERT INTO assets (asset_type, slug, content_json, category, status, approved, created_at, updated_at)
VALUES
    ('subtitle', 'subtitle-not-illegal-srt',
     JSON_OBJECT('format','srt','content','1\n00:00:00,000 --> 00:00:02,000\nThis doesn't look illegal...\n'),
     'subtitle', 'active', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

INSERT INTO assets (asset_type, slug, file_path, category, status, approved, created_at, updated_at)
VALUES
    ('overlay', 'overlay-lower-third-default',
     '/assets/brand/overlays/ptmd_overlay_lower_third.png',
     'branding', 'active', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

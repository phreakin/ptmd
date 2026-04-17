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
('hero_cta_text',        'Watch Latest Episode',                                    'string', 'Hero CTA Text',        'homepage', NOW()),
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
('ffprobe_path',         'ffprobe',                                                 'string', 'FFprobe Binary',       'system',   NOW())
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW();

-- Sample episodes
INSERT INTO episodes (title, slug, excerpt, body, thumbnail_image, featured_image, video_url, duration, status, published_at, created_at, updated_at) VALUES
(
    'City Hall After Dark',
    'city-hall-after-dark',
    'An evidence-first dive into procurement games, public money, and the people who never seem to lose a contract.',
    'Full investigative breakdown of municipal procurement patterns. Sources: city council minutes (2019–2023), FOIA-released emails, contractor registration filings, and interviews with three anonymous city department heads.\n\nThe numbers don''t lie. The contracts do.\n\nOver an 18-month period, a single contractor received 74% of discretionary IT spend without competitive bidding — citing an emergency exemption clause that was renewed 11 consecutive quarters.\n\nThis episode traces the chain: who signed off, who benefited, and why the whistleblower got reassigned instead of thanked.',
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
    'Six months covering school board meetings in three different districts. What we found: the loudest voices are almost never local parents.\n\nThis episode maps the network of outside advocacy groups coordinating public comment campaigns, the national funding sources behind local candidates, and the exhausted teachers stuck in the crossfire.\n\nFunny? Occasionally. Disturbing? Often. Well-sourced? Always.',
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

-- Episode tags
INSERT IGNORE INTO episode_tags (name, slug, created_at, updated_at) VALUES
('accountability',    'accountability',    NOW(), NOW()),
('politics',          'politics',          NOW(), NOW()),
('culture',           'culture',           NOW(), NOW()),
('corruption',        'corruption',        NOW(), NOW()),
('education',         'education',         NOW(), NOW()),
('local government',  'local-government',  NOW(), NOW()),
('follow the money',  'follow-the-money',  NOW(), NOW());

-- Tag-episode mappings
INSERT IGNORE INTO episode_tag_map (episode_id, tag_id)
SELECT e.id, t.id FROM episodes e
JOIN episode_tags t ON t.slug IN ('accountability','corruption','local-government','follow-the-money')
WHERE e.slug = 'city-hall-after-dark';

INSERT IGNORE INTO episode_tag_map (episode_id, tag_id)
SELECT e.id, t.id FROM episodes e
JOIN episode_tags t ON t.slug IN ('education','politics','culture')
WHERE e.slug = 'school-board-heat-check';

INSERT IGNORE INTO episode_tag_map (episode_id, tag_id)
SELECT e.id, t.id FROM episodes e
JOIN episode_tags t ON t.slug IN ('local-government','corruption','follow-the-money')
WHERE e.slug = 'the-permit-maze';

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
    ('YouTube',         'full documentary', 'New episode from Paper Trail MD.', '#investigation #documentary', 'queued', 1, NOW(), NOW()),
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
INSERT INTO social_post_queue (episode_id, platform, content_type, caption, asset_path, scheduled_for, status, created_at, updated_at)
SELECT
    e.id,
    'YouTube Shorts',
    'teaser',
    '🔍 City Hall After Dark — Full episode drops Sunday. Subscribe so you don''t miss it. #investigation #accountability',
    '/uploads/clips/sample_teaser.mp4',
    NOW() + INTERVAL 2 DAY,
    'queued',
    NOW(),
    NOW()
FROM episodes e WHERE e.slug = 'city-hall-after-dark' LIMIT 1;

-- Sample chat messages
INSERT INTO chat_messages (username, message, status, emojis_json, created_at, updated_at) VALUES
('FactCheckFan',         'That procurement timeline is WILD. 🔥 Keep the receipts coming.',                           'approved', JSON_ARRAY('🔥'),      NOW() - INTERVAL 3 HOUR,  NOW() - INTERVAL 3 HOUR),
('DocsOrItDidntHappen',  'Can we get the FOIA request list? Would love to dig deeper into episode 1.',               'approved', JSON_ARRAY('📄'),      NOW() - INTERVAL 2 HOUR,  NOW() - INTERVAL 2 HOUR),
('SkepticMode',          'Love the dry humor but also genuinely horrified. Appreciate the sourcing. 😅',             'approved', JSON_ARRAY('😅'),      NOW() - INTERVAL 90 MINUTE, NOW() - INTERVAL 90 MINUTE),
('CivicNerd99',          'The school board episode connects dots I had never considered. Shared everywhere.',         'approved', JSON_ARRAY(),          NOW() - INTERVAL 45 MINUTE, NOW() - INTERVAL 45 MINUTE),
('PermitSurvivor',       'Episode 3 is my whole life as a small business owner. How is this legal?? 😤',            'approved', JSON_ARRAY('😤'),      NOW() - INTERVAL 20 MINUTE, NOW() - INTERVAL 20 MINUTE);

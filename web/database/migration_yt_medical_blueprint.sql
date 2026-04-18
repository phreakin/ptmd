-- ============================================================
-- PTMD Migration: YouTube Medical System Failure Post Package
-- Seeds one video_blueprint (long-form documentary) and one
-- posting_blueprint (YouTube full-documentary launch) with a
-- schedule rule targeting Sunday 10:00 AM Phoenix time.
--
-- Idempotent — safe to re-run (INSERT … ON DUPLICATE KEY).
-- Requires schema.sql and seed.sql (posting_sites) to be applied
-- before this file.
-- ============================================================

-- ------------------------------------------------------------
-- Video Blueprint  (documentary structure for YouTube)
-- ------------------------------------------------------------
INSERT INTO video_blueprints
    (title, slug, blueprint_type, status, objective, structure_json, brand_notes,
     target_duration_sec, created_at, updated_at)
VALUES (
    'Medical System Failure — Full YouTube Documentary',
    'medical-system-failure-yt-documentary',
    'documentary',
    'active',
    'Drive subscribers and awareness around medical system accountability cases. Establish Paper Trail MD as the authoritative voice on healthcare system breakdowns. Target viewers who advocate for patients or work in healthcare.',
    JSON_OBJECT(
        'title_primary',   'The Case That Shouldn\'t Have Happened: How the System Failed This Patient',
        'title_variants',  JSON_ARRAY(
            'They Knew… And Did Nothing | A Medical System Failure Case',
            'This Should Never Happen in Healthcare (But It Did)',
            'Ignored Warnings, Permanent Damage | A Real Medical Case'
        ),
        'chapters', JSON_ARRAY(
            JSON_OBJECT('timestamp', '00:00', 'label', 'The Moment Everything Changed'),
            JSON_OBJECT('timestamp', '00:25', 'label', 'Early Warning Signs'),
            JSON_OBJECT('timestamp', '02:10', 'label', 'Missed Opportunities'),
            JSON_OBJECT('timestamp', '05:40', 'label', 'Critical Decision Point'),
            JSON_OBJECT('timestamp', '09:15', 'label', 'Escalation'),
            JSON_OBJECT('timestamp', '13:50', 'label', 'System Breakdown'),
            JSON_OBJECT('timestamp', '18:20', 'label', 'Long-Term Impact'),
            JSON_OBJECT('timestamp', '22:10', 'label', 'Lessons & Takeaways')
        ),
        'hooks', JSON_ARRAY(
            JSON_OBJECT('style', 'direct_impact',   'text', 'This case should not exist… but it does.'),
            JSON_OBJECT('style', 'curiosity_gap',   'text', 'Every warning sign was there… and still, this happened.'),
            JSON_OBJECT('style', 'emotional_pull',  'text', 'They had multiple chances to stop this. They didn\'t.')
        ),
        'thumbnail_selected', 'concept_1',
        'thumbnail_concepts', JSON_ARRAY(
            JSON_OBJECT(
                'id',          'concept_1',
                'name',        'Evidence Style',
                'background',  'Dark (#0a0a0a) or desaturated scene photo',
                'hero_visual', 'Patient silhouette, hospital bed, or redacted document — desaturated/low contrast',
                'stamp',       'Bold red block text rotated ~-5 deg — IGNORED or OVERLOOKED — font: Oswald ExtraBold or Anton, color: #CC0000',
                'subtext',     'Small white line bottom-left — "They Knew" or case title',
                'logo',        'PTMD mark top-right ~15% opacity or full white small',
                'feel',        'Dossier / evidence file aesthetic — high contrast, minimal clutter'
            ),
            JSON_OBJECT(
                'id',          'concept_2',
                'name',        'Split Contrast',
                'background',  'Left: normal hospital scene / Right: distressed or emergency situation',
                'text',        'WHAT WENT WRONG?'
            ),
            JSON_OBJECT(
                'id',          'concept_3',
                'name',        'System Failure',
                'background',  'Flowchart-style arrows breaking apart',
                'text',        'SYSTEM FAILURE',
                'subtext',     'This shouldn\'t happen'
            ),
            JSON_OBJECT(
                'id',          'concept_4',
                'name',        'Minimal Curiosity',
                'background',  'Black',
                'text',        'This should never happen.'
            )
        ),
        'in_this_case_bullets', JSON_ARRAY(
            'Repeated warning signs that were overlooked',
            'Critical decision points that changed the outcome',
            'Where intervention could have made a difference',
            'What this reveals about the system as a whole'
        ),
        'why_it_matters_bullets', JSON_ARRAY(
            'Patients advocate for themselves',
            'Providers recognize risk patterns',
            'Systems improve accountability'
        )
    ),
    'Investigative tone — authoritative, measured, and patient-focused. Avoid sensationalism; let the evidence lead. Use chapters for SEO retention. Open with the strongest hook variant that fits the specific case. Always end with the standard subscriber CTA.',
    1350,
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE
    status            = VALUES(status),
    objective         = VALUES(objective),
    structure_json    = VALUES(structure_json),
    brand_notes       = VALUES(brand_notes),
    target_duration_sec = VALUES(target_duration_sec),
    updated_at        = NOW();


-- ------------------------------------------------------------
-- Posting Blueprint  (YouTube full-documentary launch post)
-- ------------------------------------------------------------
INSERT INTO posting_blueprints
    (title, slug, site_key, content_type, status, caption_template,
     required_hashtags, cta_pattern, config_json, created_at, updated_at)
VALUES (
    'YouTube Full Documentary — Medical Case Launch',
    'yt-medical-case-launch-documentary',
    'youtube',
    'full_documentary',
    'active',
    'What happens when the system meant to protect you… fails?

In this case, we follow a patient through a series of medical decisions, delays, and breakdowns that led to lasting consequences.

This isn''t just one story. It''s a pattern.

🧾 In This Case:
Repeated warning signs that were overlooked
Critical decision points that changed the outcome
Where intervention could have made a difference
What this reveals about the system as a whole

⏱️ Timestamps
{timestamps}

🧠 Why This Matters
Cases like this highlight the gap between protocol and practice.

Understanding these failures helps:
Patients advocate for themselves
Providers recognize risk patterns
Systems improve accountability

📢 Join the Conversation
Have you experienced something similar? Drop your thoughts in the comments or share your story.

🔔 Subscribe for More Cases
We break down real-world events at the intersection of:
Healthcare Systems
Human decision-making

👉 New cases weekly.

⚠️ Disclaimer
This content is for informational and educational purposes only. It is not medical or legal advice.',

    '#medicalmalpractice #healthcaresystemfailure #patientadvocacy #medicalcasestudy #hospitalnegligence #healthcarebreakdown #realmedicaldocumentary #patientsafety #medicaldocumentary #healthcareanalysis #doctormistakes #medicalerrors #truecaseanalysis #healthcareinvestigation #systemfailure #clinicaldecisionmaking #medicalethics #patientstory #healthcareaccountability #PaperTrailMD',

    'If you want more real cases like this, hit subscribe. And if you\'ve experienced something similar… tell us below. Your story might be the next case we investigate.',

    JSON_OBJECT(
        'recommended_title_primary',  'The Case That Shouldn\'t Have Happened: How the System Failed This Patient',
        'recommended_title_variants', JSON_ARRAY(
            'They Knew… And Did Nothing | A Medical System Failure Case',
            'This Should Never Happen in Healthcare (But It Did)',
            'Ignored Warnings, Permanent Damage | A Real Medical Case'
        ),
        'thumbnail_concept',   'concept_1',
        'hook_style',          'direct_impact',
        'tag_count',           20,
        'chapter_format',      'YouTube chapters (00:00 Label)'
    ),
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE
    status            = VALUES(status),
    caption_template  = VALUES(caption_template),
    required_hashtags = VALUES(required_hashtags),
    cta_pattern       = VALUES(cta_pattern),
    config_json       = VALUES(config_json),
    updated_at        = NOW();


-- ------------------------------------------------------------
-- Schedule Rule  (Sunday 10:00 AM Phoenix — matches cadence)
-- ------------------------------------------------------------
INSERT INTO blueprint_schedule_rules
    (posting_blueprint_id, site_key, day_of_week, post_time, timezone,
     priority, min_gap_hours, max_per_day, is_active, created_at, updated_at)
SELECT
    pb.id,
    'youtube',
    'Sunday',
    '10:00:00',
    'America/Phoenix',
    1,
    0,
    1,
    1,
    NOW(),
    NOW()
FROM posting_blueprints pb
WHERE pb.slug = 'yt-medical-case-launch-documentary'
ON DUPLICATE KEY UPDATE
    priority      = VALUES(priority),
    is_active     = VALUES(is_active),
    updated_at    = NOW();

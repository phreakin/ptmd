<?php
/**
 * PTMD API — AI Generate
 *
 * POST-only AJAX endpoint. Dispatches to the correct OpenAI prompt
 * based on 'feature' field, saves the result, and returns JSON.
 *
 * Requires admin session.
 */

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Attempt to parse a JSON array payload from AI text output.
 */
function ai_parse_json_array(string $text): array
{
    $trimmed = trim($text);
    if ($trimmed === '') {
        return [];
    }

    $decoded = json_decode($trimmed, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/```(?:json)?\s*(\[.*\])\s*```/is', $trimmed, $matches)) {
        $decoded = json_decode($matches[1], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    $start = strpos($trimmed, '[');
    $end   = strrpos($trimmed, ']');
    if ($start !== false && $end !== false && $end > $start) {
        $candidate = substr($trimmed, $start, $end - $start + 1);
        $decoded   = json_decode($candidate, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

/**
 * Persist structured video ideas for later admin use.
 */
function save_video_ideas(array $ideas, ?int $generationId, ?int $createdBy, string $scope, string $contextNotes): void
{
    $pdo = get_db();
    if (!$pdo || !$ideas) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO ai_video_ideas
             (generation_id, created_by, scope, context_notes, idea_title, premise, suggested_angle, rank_order, created_at, updated_at)
             VALUES (:generation_id, :created_by, :scope, :context_notes, :idea_title, :premise, :suggested_angle, :rank_order, NOW(), NOW())'
        );

        foreach ($ideas as $idx => $idea) {
            $title = trim((string) ($idea['title'] ?? ''));
            $premise = trim((string) ($idea['premise'] ?? ''));
            $angle = trim((string) ($idea['angle'] ?? ''));

            if ($title === '' || $premise === '' || $angle === '') {
                continue;
            }

            $stmt->execute([
                'generation_id'  => $generationId,
                'created_by'     => $createdBy,
                'scope'          => $scope,
                'context_notes'  => $contextNotes === '' ? null : $contextNotes,
                'idea_title'     => mb_substr($title, 0, 255),
                'premise'        => $premise,
                'suggested_angle'=> $angle,
                'rank_order'     => $idx + 1,
            ]);
        }
    } catch (Throwable $e) {
        error_log('[PTMD] Failed to save ai_video_ideas: ' . $e->getMessage());
    }
}

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!is_post()) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$feature = trim((string) ($_POST['feature'] ?? ''));

$allowed = ['video_ideas', 'title', 'keywords', 'description', 'caption', 'thumbnail_concept', 'episode_field_suggestion', 'episode_field_optimize'];
if (!in_array($feature, $allowed, true)) {
    echo json_encode(['ok' => false, 'error' => 'Unknown feature: ' . e($feature)]);
    exit;
}

$systemPrompt = ptmd_ai_system_prompt();
$episodeId    = null;

// ── Build prompts per feature ──────────────────────────────────────────────────

switch ($feature) {

    case 'video_ideas':
        $theme = trim((string) ($_POST['ideas_theme'] ?? ''));
        $scope = strtolower(trim((string) ($_POST['ideas_scope'] ?? 'both')));
        if (!in_array($scope, ['us', 'world', 'both'], true)) {
            $scope = 'both';
        }
        $currentContext = trim((string) ($_POST['ideas_context'] ?? ''));
        $count = max(1, min(15, (int) ($_POST['ideas_count'] ?? 5)));
        $themeClause = $theme ? " Focus on the theme or keyword: \"{$theme}\"." : '';
        $scopeLabel = $scope === 'us'
            ? 'the United States'
            : ($scope === 'world' ? 'global issues outside the U.S.' : 'both U.S. and global issues');
        $contextClause = $currentContext !== ''
            ? "Use this additional current-context guidance: {$currentContext}\n\n"
            : '';

        $userPrompt = "Today is " . date('Y-m-d') . ". Generate {$count} compelling documentary episode ideas for Paper Trail MD based on current social situations and issues in {$scopeLabel}.{$themeClause}\n\n"
            . $contextClause
            . "Return ONLY valid JSON (no markdown, no commentary) as an array where each item has exactly these keys:\n"
            . "- title\n"
            . "- premise\n"
            . "- angle\n";
        break;

    case 'title':
        $topic     = trim((string) ($_POST['title_topic'] ?? ''));
        $epId      = (int) ($_POST['title_episode'] ?? 0);
        $episodeId = $epId ?: null;

        $context = $topic;
        if ($epId > 0) {
            $pdo = get_db();
            if ($pdo) {
                $ep = $pdo->prepare('SELECT title, excerpt FROM episodes WHERE id = :id');
                $ep->execute(['id' => $epId]);
                $epRow = $ep->fetch();
                if ($epRow) {
                    $context = $epRow['title'] . ': ' . $epRow['excerpt'];
                }
            }
        }

        if (!$context) {
            echo json_encode(['ok' => false, 'error' => 'Provide a topic or select an episode.']);
            exit;
        }

        $userPrompt = "Generate 8 compelling episode titles for a PTMD documentary.\n"
            . "Topic/premise: {$context}\n\n"
            . "Requirements:\n"
            . "- Punchy, investigative, brand-appropriate\n"
            . "- Mix of styles: dramatic, ironic, question-based, statement-based\n"
            . "- No clickbait; real and credible\n"
            . "Output as a numbered list.";
        break;

    case 'keywords':
        $topic    = trim((string) ($_POST['kw_topic']    ?? ''));
        $platform = trim((string) ($_POST['kw_platform'] ?? 'YouTube'));

        if (!$topic) {
            echo json_encode(['ok' => false, 'error' => 'Provide a topic.']);
            exit;
        }

        $userPrompt = "Generate SEO keywords and tags for a PTMD documentary on: \"{$topic}\"\n"
            . "Target platform: {$platform}\n\n"
            . "Output:\n"
            . "1. 15 YouTube tags (comma-separated)\n"
            . "2. 10 hashtags for social (one per line with #)\n"
            . "3. 5 long-tail SEO phrases\n"
            . "Keep them specific and accurate to the topic.";
        break;

    case 'description':
        $epId  = (int) ($_POST['desc_episode'] ?? 0);
        $notes = trim((string) ($_POST['desc_notes'] ?? ''));
        $episodeId = $epId ?: null;

        $context = $notes;
        if ($epId > 0) {
            $pdo = get_db();
            if ($pdo) {
                $ep = $pdo->prepare('SELECT title, excerpt, body FROM episodes WHERE id = :id');
                $ep->execute(['id' => $epId]);
                $epRow = $ep->fetch();
                if ($epRow) {
                    $context = "Title: {$epRow['title']}\nExcerpt: {$epRow['excerpt']}\n\n"
                             . mb_substr((string) $epRow['body'], 0, 500);
                }
            }
        }

        if (!$context) {
            echo json_encode(['ok' => false, 'error' => 'Select an episode or add notes.']);
            exit;
        }

        $userPrompt = "Write a full YouTube video description for a PTMD documentary.\n\n"
            . "Episode context:\n{$context}\n\n"
            . "Additional notes: {$notes}\n\n"
            . "Requirements:\n"
            . "- Strong first 2 lines (visible in search without expanding)\n"
            . "- 250–400 words total\n"
            . "- Include a section for links (use placeholder: [SUBSCRIBE LINK], [SOCIAL LINKS])\n"
            . "- End with 5–8 relevant hashtags\n"
            . "- Tone: investigative, sharp, slightly sardonic — no fluff";
        break;

    case 'caption':
        $epId     = (int) ($_POST['cap_episode'] ?? 0);
        $platform = trim((string) ($_POST['cap_platform'] ?? 'all'));
        $episodeId = $epId ?: null;

        $context = '';
        if ($epId > 0) {
            $pdo = get_db();
            if ($pdo) {
                $ep = $pdo->prepare('SELECT title, excerpt FROM episodes WHERE id = :id');
                $ep->execute(['id' => $epId]);
                $epRow = $ep->fetch();
                if ($epRow) {
                    $context = "Title: {$epRow['title']}\nExcerpt: {$epRow['excerpt']}";
                }
            }
        }

        if (!$context) {
            echo json_encode(['ok' => false, 'error' => 'Select an episode.']);
            exit;
        }

        $platformList = $platform === 'all'
            ? 'YouTube Shorts, TikTok, Instagram Reels, X (Twitter), and Facebook Reels'
            : $platform;

        $userPrompt = "Write social media captions for a PTMD episode.\n\n"
            . "Episode:\n{$context}\n\n"
            . "Platforms: {$platformList}\n\n"
            . "For each platform write a separate caption that:\n"
            . "- Fits the character limit and style of that platform\n"
            . "- Has a strong hook in the first line\n"
            . "- Includes relevant emojis (not excessive)\n"
            . "- Ends with 2–4 hashtags\n"
            . "- Matches the PTMD brand voice: investigative, sharp, a little funny\n\n"
            . "Label each caption clearly.";
        break;

    case 'thumbnail_concept':
        $epId  = (int) ($_POST['thumb_episode'] ?? 0);
        $style = trim((string) ($_POST['thumb_style'] ?? 'PTMD cinematic dark'));
        $episodeId = $epId ?: null;

        $context = '';
        if ($epId > 0) {
            $pdo = get_db();
            if ($pdo) {
                $ep = $pdo->prepare('SELECT title, excerpt FROM episodes WHERE id = :id');
                $ep->execute(['id' => $epId]);
                $epRow = $ep->fetch();
                if ($epRow) {
                    $context = "Title: {$epRow['title']}\nExcerpt: {$epRow['excerpt']}";
                }
            }
        }

        if (!$context) {
            echo json_encode(['ok' => false, 'error' => 'Select an episode.']);
            exit;
        }

        $userPrompt = "Describe a compelling YouTube thumbnail concept for this PTMD documentary.\n\n"
            . "Episode:\n{$context}\n\n"
            . "Visual style: {$style}\n\n"
            . "Output:\n"
            . "1. Main image / subject: what should be prominently shown?\n"
            . "2. Text overlay: what bold text (max 5 words) should appear?\n"
            . "3. Color treatment: specific colors from the PTMD palette to use\n"
            . "4. Composition: describe the layout (rule of thirds, centered, split, etc.)\n"
            . "5. Mood and emotion the thumbnail should convey\n"
            . "6. Any PTMD brand elements to include (logo placement, overlay, etc.)\n\n"
            . "Be specific and visual-director precise.";
        break;

    case 'episode_field_suggestion':
        $field = trim((string) ($_POST['suggest_field'] ?? ''));
        $allowedFields = [
            'title' => 'Title',
            'slug' => 'Slug',
            'excerpt' => 'Excerpt',
            'body' => 'Body',
            'keywords' => 'Keywords',
            'video_url' => 'Video URL',
            'duration' => 'Duration',
            'thumbnail_image' => 'Thumbnail image path/URL',
        ];
        if (!isset($allowedFields[$field])) {
            echo json_encode(['ok' => false, 'error' => 'Unknown field requested.']);
            exit;
        }

        $epId = (int) ($_POST['suggest_episode'] ?? 0);
        $episodeId = $epId ?: null;
        $guidance = trim((string) ($_POST['suggest_guidance'] ?? ''));

        $contextTitle = trim((string) ($_POST['context_title'] ?? ''));
        $contextExcerpt = trim((string) ($_POST['context_excerpt'] ?? ''));
        $contextBody = trim((string) ($_POST['context_body'] ?? ''));
        $contextKeywords = trim((string) ($_POST['context_keywords'] ?? ''));
        $contextVideoUrl = trim((string) ($_POST['context_video_url'] ?? ''));
        $contextDuration = trim((string) ($_POST['context_duration'] ?? ''));
        $contextThumbnail = trim((string) ($_POST['context_thumbnail_image'] ?? ''));

        if ($epId > 0) {
            $pdo = get_db();
            if ($pdo) {
                $ep = $pdo->prepare(
                    'SELECT e.title, e.excerpt, e.body, e.video_url, e.duration, e.thumbnail_image,
                     (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ", ")
                      FROM episode_tag_map m INNER JOIN episode_tags t ON t.id = m.tag_id
                      WHERE m.episode_id = e.id) AS keywords
                     FROM episodes e WHERE e.id = :id LIMIT 1'
                );
                $ep->execute(['id' => $epId]);
                $epRow = $ep->fetch();
                if ($epRow) {
                    $contextTitle = $contextTitle !== '' ? $contextTitle : (string) ($epRow['title'] ?? '');
                    $contextExcerpt = $contextExcerpt !== '' ? $contextExcerpt : (string) ($epRow['excerpt'] ?? '');
                    $contextBody = $contextBody !== '' ? $contextBody : (string) ($epRow['body'] ?? '');
                    $contextKeywords = $contextKeywords !== '' ? $contextKeywords : (string) ($epRow['keywords'] ?? '');
                    $contextVideoUrl = $contextVideoUrl !== '' ? $contextVideoUrl : (string) ($epRow['video_url'] ?? '');
                    $contextDuration = $contextDuration !== '' ? $contextDuration : (string) ($epRow['duration'] ?? '');
                    $contextThumbnail = $contextThumbnail !== '' ? $contextThumbnail : (string) ($epRow['thumbnail_image'] ?? '');
                }
            }
        }

        $context = "Title: {$contextTitle}\n"
            . "Excerpt: {$contextExcerpt}\n"
            . "Body: " . mb_substr($contextBody, 0, 900) . "\n"
            . "Keywords: {$contextKeywords}\n"
            . "Video URL: {$contextVideoUrl}\n"
            . "Duration: {$contextDuration}\n"
            . "Thumbnail: {$contextThumbnail}\n";

        $guidanceClause = $guidance !== '' ? "Additional admin guidance: {$guidance}\n\n" : '';
        $fieldRuleMap = [
            'title' => 'Provide one title line only (max 120 chars).',
            'slug' => 'Provide one URL-safe lowercase slug line only using letters, numbers, and hyphens.',
            'excerpt' => 'Provide one concise excerpt paragraph (1-2 sentences, max 300 chars).',
            'body' => 'Provide one polished body draft section (around 180-300 words).',
            'keywords' => 'Provide one comma-separated list of 8-12 relevant keywords.',
            'video_url' => 'Provide either a suggested embed URL format or a clear placeholder URL line.',
            'duration' => 'Provide one duration value in mm:ss format only.',
            'thumbnail_image' => 'Provide one concise suggested thumbnail image path/URL or naming suggestion line.',
        ];
        $fieldRule = $fieldRuleMap[$field];

        $userPrompt = "Suggest content for the '{$allowedFields[$field]}' field for a PTMD episode edit form.\n\n"
            . $guidanceClause
            . "Current episode context:\n{$context}\n"
            . "Output rules:\n"
            . "- Return only the suggestion text, no labels\n"
            . "- Keep tone investigative, sharp, credible\n"
            . "- {$fieldRule}\n";
        break;

    case 'episode_field_optimize':
        $field = trim((string) ($_POST['suggest_field'] ?? ''));
        $allowedFields = [
            'title' => 'Title',
            'slug' => 'Slug',
            'excerpt' => 'Excerpt',
            'body' => 'Body',
            'keywords' => 'Keywords',
            'video_url' => 'Video URL',
            'duration' => 'Duration',
            'thumbnail_image' => 'Thumbnail image path/URL',
        ];
        if (!isset($allowedFields[$field])) {
            echo json_encode(['ok' => false, 'error' => 'Unknown field requested.']);
            exit;
        }

        $epId = (int) ($_POST['suggest_episode'] ?? 0);
        $episodeId = $epId ?: null;
        $guidance = trim((string) ($_POST['suggest_guidance'] ?? ''));

        $contextTitle = trim((string) ($_POST['context_title'] ?? ''));
        $contextExcerpt = trim((string) ($_POST['context_excerpt'] ?? ''));
        $contextBody = trim((string) ($_POST['context_body'] ?? ''));
        $contextKeywords = trim((string) ($_POST['context_keywords'] ?? ''));
        $contextVideoUrl = trim((string) ($_POST['context_video_url'] ?? ''));
        $contextDuration = trim((string) ($_POST['context_duration'] ?? ''));
        $contextThumbnail = trim((string) ($_POST['context_thumbnail_image'] ?? ''));
        $sourceText = trim((string) ($_POST['optimize_source'] ?? ''));

        if ($epId > 0) {
            $pdo = get_db();
            if ($pdo) {
                $ep = $pdo->prepare(
                    'SELECT e.title, e.excerpt, e.body, e.video_url, e.duration, e.thumbnail_image,
                     (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ", ")
                      FROM episode_tag_map m INNER JOIN episode_tags t ON t.id = m.tag_id
                      WHERE m.episode_id = e.id) AS keywords
                     FROM episodes e WHERE e.id = :id LIMIT 1'
                );
                $ep->execute(['id' => $epId]);
                $epRow = $ep->fetch();
                if ($epRow) {
                    $contextTitle = $contextTitle !== '' ? $contextTitle : (string) ($epRow['title'] ?? '');
                    $contextExcerpt = $contextExcerpt !== '' ? $contextExcerpt : (string) ($epRow['excerpt'] ?? '');
                    $contextBody = $contextBody !== '' ? $contextBody : (string) ($epRow['body'] ?? '');
                    $contextKeywords = $contextKeywords !== '' ? $contextKeywords : (string) ($epRow['keywords'] ?? '');
                    $contextVideoUrl = $contextVideoUrl !== '' ? $contextVideoUrl : (string) ($epRow['video_url'] ?? '');
                    $contextDuration = $contextDuration !== '' ? $contextDuration : (string) ($epRow['duration'] ?? '');
                    $contextThumbnail = $contextThumbnail !== '' ? $contextThumbnail : (string) ($epRow['thumbnail_image'] ?? '');
                }
            }
        }

        if ($sourceText === '') {
            echo json_encode(['ok' => false, 'error' => 'Add field text before optimizing.']);
            exit;
        }

        $context = "Title: {$contextTitle}\n"
            . "Excerpt: {$contextExcerpt}\n"
            . "Body: " . mb_substr($contextBody, 0, 900) . "\n"
            . "Keywords: {$contextKeywords}\n"
            . "Video URL: {$contextVideoUrl}\n"
            . "Duration: {$contextDuration}\n"
            . "Thumbnail: {$contextThumbnail}\n";

        $guidanceClause = $guidance !== '' ? "Additional admin guidance: {$guidance}\n\n" : '';
        $optimizationRules = [
            'title' => 'Return one improved title line only (max 120 chars).',
            'slug' => 'Return one URL-safe lowercase slug line only using letters, numbers, and hyphens.',
            'excerpt' => 'Return one improved excerpt paragraph (1-2 sentences, max 300 chars).',
            'body' => 'Return one improved body section with clearer structure and flow (about 180-320 words).',
            'keywords' => 'Return one improved comma-separated list of 8-12 relevant keywords.',
            'video_url' => 'Return one cleaned or corrected embed URL line only.',
            'duration' => 'Return one duration value in mm:ss format only.',
            'thumbnail_image' => 'Return one cleaned and sensible thumbnail path/URL line only.',
        ];
        $fieldRule = $optimizationRules[$field];

        $userPrompt = "Optimize the existing '{$allowedFields[$field]}' text for a PTMD episode edit form.\n\n"
            . $guidanceClause
            . "Current field text to optimize:\n{$sourceText}\n\n"
            . "Current episode context:\n{$context}\n"
            . "Output rules:\n"
            . "- Return only the optimized text, no labels\n"
            . "- Preserve factual meaning and avoid adding unsupported claims\n"
            . "- Keep tone investigative, sharp, and credible\n"
            . "- {$fieldRule}\n";
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown feature']);
        exit;
}

// ── Call OpenAI ───────────────────────────────────────────────────────────────
$result = openai_chat($systemPrompt, $userPrompt, 1200);

if (!$result['ok']) {
    echo json_encode(['ok' => false, 'error' => $result['error']]);
    exit;
}

// ── Save to DB ────────────────────────────────────────────────────────────────
$generationId = save_ai_generation(
    $feature,
    $userPrompt,
    $result['text'],
    $result['model'],
    $result['prompt_tokens'],
    $result['response_tokens'],
    $episodeId
);

$ideas = [];
if ($feature === 'video_ideas') {
    $scope = strtolower(trim((string) ($_POST['ideas_scope'] ?? 'both')));
    if (!in_array($scope, ['us', 'world', 'both'], true)) {
        $scope = 'both';
    }
    $contextNotes = trim((string) ($_POST['ideas_context'] ?? ''));
    $parsed = ai_parse_json_array($result['text']);

    foreach ($parsed as $item) {
        if (!is_array($item)) {
            continue;
        }
        $title = trim((string) ($item['title'] ?? ''));
        $premise = trim((string) ($item['premise'] ?? ''));
        $angle = trim((string) ($item['angle'] ?? ''));
        if ($title === '' || $premise === '' || $angle === '') {
            continue;
        }
        $ideas[] = [
            'title' => $title,
            'premise' => $premise,
            'angle' => $angle,
        ];
    }

    if ($ideas) {
        $admin = current_admin();
        save_video_ideas($ideas, $generationId ?: null, isset($admin['id']) ? (int) $admin['id'] : null, $scope, $contextNotes);
    }
}

echo json_encode([
    'ok'    => true,
    'text'  => $result['text'],
    'ideas' => $ideas,
]);

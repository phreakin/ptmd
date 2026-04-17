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

$allowed = ['video_ideas', 'title', 'keywords', 'description', 'caption', 'thumbnail_concept'];
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
        $count = max(1, min(15, (int) ($_POST['ideas_count'] ?? 5)));
        $themeClause = $theme ? " Focus on the theme or keyword: \"{$theme}\"." : '';

        $userPrompt = "Generate {$count} compelling documentary episode ideas for Paper Trail MD.{$themeClause}\n\n"
            . "For each idea:\n"
            . "1. One-line working title\n"
            . "2. One-sentence premise\n"
            . "3. Suggested angle or hook\n\n"
            . "Output as a numbered list.";
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
save_ai_generation(
    $feature,
    $userPrompt,
    $result['text'],
    $result['model'],
    $result['prompt_tokens'],
    $result['response_tokens'],
    $episodeId
);

echo json_encode([
    'ok'   => true,
    'text' => $result['text'],
]);

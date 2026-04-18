<?php

declare(strict_types=1);

/**
 * PTMD — Content Generation Service
 * AI-powered generation of titles, captions, thumbnail text, CTAs, and script outlines.
 */

require_once __DIR__ . '/EventTrackingService.php';

/**
 * Generate title variants for a case.
 *
 * @param int   $caseId
 * @param array $context  Optional override context
 * @return array ['ok'=>bool, 'titles'=>string[], 'generation_id'=>int, 'error'=>string|null]
 */
function ptmd_generate_titles(int $caseId, array $context = []): array
{
    $pdo = get_db();
    if (!$pdo) {
        return ['ok' => false, 'titles' => [], 'generation_id' => 0, 'error' => 'No database connection'];
    }

    try {
        $cStmt = $pdo->prepare('SELECT * FROM cases WHERE id = :id LIMIT 1');
        $cStmt->execute([':id' => $caseId]);
        $case = $cStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$case) {
            return ['ok' => false, 'titles' => [], 'generation_id' => 0, 'error' => "Case #{$caseId} not found"];
        }

        // Load top 3 active trend clusters
        $tStmt = $pdo->prepare(
            "SELECT label, trend_score FROM trend_clusters
              WHERE status = 'active'
              ORDER BY trend_score DESC
              LIMIT 3"
        );
        $tStmt->execute();
        $trends = $tStmt->fetchAll(\PDO::FETCH_ASSOC);

        $trendContext = '';
        if (!empty($trends)) {
            $trendContext = "\n\nACTIVE TRENDS:\n" . implode("\n", array_map(
                fn($t) => '- ' . $t['label'] . ' (score: ' . $t['trend_score'] . ')',
                $trends
            ));
        }

        $title       = $case['title']       ?? 'Untitled';
        $description = $case['description'] ?? $case['summary'] ?? '';

        $prompt = <<<PROMPT
You are a PTMD title strategist. PTMD makes investigative, evidence-driven documentary content.
Tone: sharp, direct, credible, no corporate safety, no clickbait.

CASE TITLE: {$title}
CASE DESCRIPTION: {$description}{$trendContext}

Generate exactly 5 title variants:
1. Declarative investigative title (states a fact or finding directly)
2. Question title (provokes curiosity with a genuine question)
3. Data-driven title (leads with a specific number, stat, or timeline)
4. Cultural-reference title (connects to a broader cultural moment or comparison)
5. Short punchy title (max 7 words, hard-hitting)

RULES:
- No "How", "Why", "What" openings on the declarative title.
- No "You won't believe" or generic hooks.
- Each title must feel like it could open a real documentary.
- Reflect PTMD voice: investigative, cinematic, evidence-based.

Output ONLY a valid JSON array of 5 strings. No prose outside the JSON block.

```json
["title1", "title2", "title3", "title4", "title5"]
```
PROMPT;

        $systemPrompt = ptmd_ai_system_prompt();
        $aiResult     = openai_chat($systemPrompt, $prompt, 600);

        if (empty($aiResult['content'])) {
            return ['ok' => false, 'titles' => [], 'generation_id' => 0, 'error' => 'AI returned no content'];
        }

        $rawContent = $aiResult['content'];
        $jsonString = $rawContent;
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $rawContent, $matches)) {
            $jsonString = $matches[1];
        }

        $titles = json_decode(trim($jsonString), true);
        if (!is_array($titles)) {
            $titles = array_filter(explode("\n", $rawContent), fn($l) => trim($l) !== '');
            $titles = array_values($titles);
        }

        $titles = array_values(array_filter(array_map('strval', $titles)));

        $model          = $aiResult['model']            ?? 'gpt-4o-mini';
        $promptTokens   = (int) ($aiResult['prompt_tokens']   ?? 0);
        $responseTokens = (int) ($aiResult['response_tokens'] ?? 0);

        $genId = save_ai_generation('title_generation', $prompt, $rawContent, $model, $promptTokens, $responseTokens, $caseId);
        ptmd_record_ai_cost('title_generation', $model, $promptTokens, $responseTokens, null, $genId);

        ptmd_emit_event(
            'content.title.generated',
            'content_generation',
            'case',
            $caseId,
            ['count' => count($titles), 'generation_id' => $genId],
            null,
            ptmd_generate_trace_id(),
            null, null, null, null,
            'generated',
            'ai'
        );

        return ['ok' => true, 'titles' => $titles, 'generation_id' => $genId, 'error' => null];
    } catch (\Throwable $e) {
        error_log('[PTMD ContentGen] ptmd_generate_titles failed: ' . $e->getMessage());
        return ['ok' => false, 'titles' => [], 'generation_id' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Generate caption variants per platform for a case/clip.
 *
 * @param int         $caseId
 * @param string|null $platform  Target platform; null = generate for all platforms
 * @return array ['ok'=>bool, 'captions'=>[platform=>string], 'generation_id'=>int, 'error'=>string|null]
 */
function ptmd_generate_captions(int $caseId, ?string $platform = null): array
{
    $allPlatforms = ['youtube', 'tiktok', 'instagram_reels', 'facebook_reels', 'x'];
    $platforms    = $platform !== null ? [$platform] : $allPlatforms;

    $pdo = get_db();
    if (!$pdo) {
        return ['ok' => false, 'captions' => [], 'generation_id' => 0, 'error' => 'No database connection'];
    }

    try {
        $cStmt = $pdo->prepare('SELECT * FROM cases WHERE id = :id LIMIT 1');
        $cStmt->execute([':id' => $caseId]);
        $case = $cStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$case) {
            return ['ok' => false, 'captions' => [], 'generation_id' => 0, 'error' => "Case #{$caseId} not found"];
        }

        $title       = $case['title']       ?? 'Untitled';
        $description = $case['description'] ?? $case['summary'] ?? '';

        $platformRules = <<<RULES
Platform tone rules:
- tiktok: punchy, first line = conflict/hook, main hook max 150 chars, casual language, trending angle
- instagram_reels: punchy, first line = conflict/hook, main hook max 150 chars, visual storytelling
- youtube: context-rich, promise structure, can be multi-paragraph, keywords in first sentence
- facebook_reels: conversational, question-driven, community angle, slightly longer format
- x: concise thesis + provocative angle, max 280 chars total, no hashtag spam
RULES;

        $platformList = implode(', ', $platforms);
        $prompt       = <<<PROMPT
You are a PTMD platform caption writer. PTMD tone: investigative, sharp, evidence-driven, no corporate-safe language.

CASE TITLE: {$title}
CASE DESCRIPTION: {$description}
PLATFORMS TO GENERATE FOR: {$platformList}

{$platformRules}

Generate one caption per requested platform. Each caption must:
- Open with the strongest conflict or revelation (no warm-up)
- Match the platform's character limits and tone
- Include a call-to-action appropriate to the platform
- Never use generic phrases like "Check this out" or "You need to see this"

Output ONLY a valid JSON object with platform names as keys and caption strings as values.

```json
{"youtube": "...", "tiktok": "...", "instagram_reels": "...", "facebook_reels": "...", "x": "..."}
```
PROMPT;

        $systemPrompt = ptmd_ai_system_prompt();
        $aiResult     = openai_chat($systemPrompt, $prompt, 800);

        if (empty($aiResult['content'])) {
            return ['ok' => false, 'captions' => [], 'generation_id' => 0, 'error' => 'AI returned no content'];
        }

        $rawContent = $aiResult['content'];
        $jsonString = $rawContent;
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $rawContent, $matches)) {
            $jsonString = $matches[1];
        }

        $captions = json_decode(trim($jsonString), true);
        if (!is_array($captions)) {
            $captions = [];
        }

        // Filter to only requested platforms
        $captions = array_intersect_key($captions, array_flip($platforms));

        $model          = $aiResult['model']            ?? 'gpt-4o-mini';
        $promptTokens   = (int) ($aiResult['prompt_tokens']   ?? 0);
        $responseTokens = (int) ($aiResult['response_tokens'] ?? 0);

        $genId = save_ai_generation('caption_generation', $prompt, $rawContent, $model, $promptTokens, $responseTokens, $caseId);
        ptmd_record_ai_cost('caption_generation', $model, $promptTokens, $responseTokens, null, $genId);

        ptmd_emit_event(
            'content.caption.generated',
            'content_generation',
            'case',
            $caseId,
            ['platforms' => array_keys($captions), 'generation_id' => $genId],
            null,
            ptmd_generate_trace_id(),
            null, null, null, null,
            'generated',
            'ai'
        );

        return ['ok' => true, 'captions' => $captions, 'generation_id' => $genId, 'error' => null];
    } catch (\Throwable $e) {
        error_log('[PTMD ContentGen] ptmd_generate_captions failed: ' . $e->getMessage());
        return ['ok' => false, 'captions' => [], 'generation_id' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Generate thumbnail text variants.
 *
 * @param int   $caseId
 * @param array $context  May include: retention_target (high/normal), sensitivity (0-100)
 * @return array ['ok'=>bool, 'thumbnail_texts'=>string[], 'generation_id'=>int, 'error'=>string|null]
 */
function ptmd_generate_thumbnail_text(int $caseId, array $context = []): array
{
    $pdo = get_db();
    if (!$pdo) {
        return ['ok' => false, 'thumbnail_texts' => [], 'generation_id' => 0, 'error' => 'No database connection'];
    }

    try {
        $cStmt = $pdo->prepare('SELECT * FROM cases WHERE id = :id LIMIT 1');
        $cStmt->execute([':id' => $caseId]);
        $case = $cStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$case) {
            return ['ok' => false, 'thumbnail_texts' => [], 'generation_id' => 0, 'error' => "Case #{$caseId} not found"];
        }

        $title       = $case['title']       ?? 'Untitled';
        $description = $case['description'] ?? $case['summary'] ?? '';
        $sensitivity = (float) ($context['sensitivity'] ?? 0);
        $highRetention = ($context['retention_target'] ?? 'normal') === 'high';

        $rules = $highRetention
            ? 'Focus on 3-5 word hard claims. Documentary tone. Maximum impact per word.'
            : 'Balanced, informative, and credible. Not sensational.';
        if ($sensitivity > 60) {
            $rules .= ' Use neutral forensic wording. Avoid inflammatory language.';
        }

        $prompt = <<<PROMPT
You are a PTMD thumbnail text specialist. PTMD creates investigative documentary content.

CASE TITLE: {$title}
CASE DESCRIPTION: {$description}
RULES: {$rules}

Generate exactly 4 thumbnail text variants:
1. AGGRESSIVE: Hard-hitting claim, maximum tension (3-6 words)
2. NEUTRAL: Informative, journalistically credible (4-7 words)
3. QUESTION: Provocative question that creates information gap (4-8 words)
4. NUMERIC: Leads with a number or statistic (3-6 words)

PTMD style: no shouting caps, no emojis, no exclamation marks. Cinematic restraint.

Output ONLY a valid JSON array of 4 strings.

```json
["aggressive text", "neutral text", "question text?", "numeric text"]
```
PROMPT;

        $systemPrompt = ptmd_ai_system_prompt();
        $aiResult     = openai_chat($systemPrompt, $prompt, 400);

        if (empty($aiResult['content'])) {
            return ['ok' => false, 'thumbnail_texts' => [], 'generation_id' => 0, 'error' => 'AI returned no content'];
        }

        $rawContent = $aiResult['content'];
        $jsonString = $rawContent;
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $rawContent, $matches)) {
            $jsonString = $matches[1];
        }

        $texts = json_decode(trim($jsonString), true);
        if (!is_array($texts)) {
            $texts = [];
        }
        $texts = array_values(array_filter(array_map('strval', $texts)));

        $model          = $aiResult['model']            ?? 'gpt-4o-mini';
        $promptTokens   = (int) ($aiResult['prompt_tokens']   ?? 0);
        $responseTokens = (int) ($aiResult['response_tokens'] ?? 0);

        $genId = save_ai_generation('thumbnail_text_generation', $prompt, $rawContent, $model, $promptTokens, $responseTokens, $caseId);
        ptmd_record_ai_cost('thumbnail_text_generation', $model, $promptTokens, $responseTokens, null, $genId);

        ptmd_emit_event(
            'content.thumbnail_text.generated',
            'content_generation',
            'case',
            $caseId,
            ['count' => count($texts), 'generation_id' => $genId],
            null,
            ptmd_generate_trace_id(),
            null, null, null, null,
            'generated',
            'ai'
        );

        return ['ok' => true, 'thumbnail_texts' => $texts, 'generation_id' => $genId, 'error' => null];
    } catch (\Throwable $e) {
        error_log('[PTMD ContentGen] ptmd_generate_thumbnail_text failed: ' . $e->getMessage());
        return ['ok' => false, 'thumbnail_texts' => [], 'generation_id' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Generate CTA variants for a piece of content.
 *
 * @param string $contentType  'teaser_clip', 'longform', 'underperforming'
 * @param string $platform     Target platform
 * @param array  $context      Optional extra context
 * @return array ['ok'=>bool, 'ctas'=>string[], 'error'=>string|null]
 */
function ptmd_generate_cta(string $contentType, string $platform, array $context = []): array
{
    $ctaLogic = match ($contentType) {
        'teaser_clip'     => 'Focus on driving viewers to the full-length documentary. E.g. "Watch the full breakdown" variants. 3 options ranging from direct to curiosity-driven.',
        'longform'        => 'Focus on subscription and next-case discovery. Include subscribe prompt and tease the next investigation.',
        'underperforming' => 'Focus on comment-driving CTAs that re-engage passive viewers. Ask a provocative question tied to the case.',
        default           => 'Generate 3 general CTAs appropriate for this content and platform.',
    };

    $prompt = <<<PROMPT
You are a PTMD CTA copywriter. PTMD tone: investigative, credible, direct.

CONTENT TYPE: {$contentType}
PLATFORM: {$platform}
CTA STRATEGY: {$ctaLogic}

Generate exactly 3 CTA strings. Each must:
- Be short (max 15 words)
- Match PTMD voice (no generic "Subscribe for more" style language)
- Be appropriate for {$platform}

Output ONLY a valid JSON array of 3 strings.

```json
["cta1", "cta2", "cta3"]
```
PROMPT;

    try {
        $systemPrompt = ptmd_ai_system_prompt();
        $aiResult     = openai_chat($systemPrompt, $prompt, 300);

        if (empty($aiResult['content'])) {
            return ['ok' => false, 'ctas' => [], 'error' => 'AI returned no content'];
        }

        $rawContent = $aiResult['content'];
        $jsonString = $rawContent;
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $rawContent, $matches)) {
            $jsonString = $matches[1];
        }

        $ctas = json_decode(trim($jsonString), true);
        if (!is_array($ctas)) {
            $ctas = [];
        }
        $ctas = array_values(array_filter(array_map('strval', $ctas)));

        $model          = $aiResult['model']            ?? 'gpt-4o-mini';
        $promptTokens   = (int) ($aiResult['prompt_tokens']   ?? 0);
        $responseTokens = (int) ($aiResult['response_tokens'] ?? 0);
        ptmd_record_ai_cost('cta_generation', $model, $promptTokens, $responseTokens);

        return ['ok' => true, 'ctas' => $ctas, 'error' => null];
    } catch (\Throwable $e) {
        error_log('[PTMD ContentGen] ptmd_generate_cta failed: ' . $e->getMessage());
        return ['ok' => false, 'ctas' => [], 'error' => $e->getMessage()];
    }
}

/**
 * Generate script outline blocks for a case.
 *
 * @param int      $caseId
 * @param int|null $blueprintId  Optional case_blueprints.id to use as structural template
 * @return array ['ok'=>bool, 'sections'=>array, 'generation_id'=>int, 'error'=>string|null]
 */
function ptmd_generate_script_outline(int $caseId, ?int $blueprintId = null): array
{
    $pdo = get_db();
    if (!$pdo) {
        return ['ok' => false, 'sections' => [], 'generation_id' => 0, 'error' => 'No database connection'];
    }

    try {
        $cStmt = $pdo->prepare('SELECT * FROM cases WHERE id = :id LIMIT 1');
        $cStmt->execute([':id' => $caseId]);
        $case = $cStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$case) {
            return ['ok' => false, 'sections' => [], 'generation_id' => 0, 'error' => "Case #{$caseId} not found"];
        }

        $blueprintContext = '';
        if ($blueprintId) {
            $bStmt = $pdo->prepare('SELECT * FROM case_blueprints WHERE id = :id LIMIT 1');
            $bStmt->execute([':id' => $blueprintId]);
            $blueprint = $bStmt->fetch(\PDO::FETCH_ASSOC);
            if ($blueprint) {
                $blueprintContext = "\n\nBLUEPRINT STRUCTURE:\n" . ($blueprint['structure'] ?? $blueprint['outline'] ?? '');
            }
        }

        $title       = $case['title']       ?? 'Untitled';
        $description = $case['description'] ?? $case['summary'] ?? '';

        $prompt = <<<PROMPT
You are a PTMD documentary script architect. PTMD creates investigative, evidence-driven content.
Structure: cold open with maximum tension, build through evidence, cinematic resolution.

CASE TITLE: {$title}
CASE DESCRIPTION: {$description}{$blueprintContext}

Generate a script outline with the following sections:
1. COLD OPEN (hook + immediate tension, 30-60 seconds)
2. CONTEXT ESTABLISHMENT (who, what, where — journalistic framing)
3. EVIDENCE LAYER 1 (first core finding or revelation)
4. COMPLICATION (the twist or obstacle that deepens the story)
5. EVIDENCE LAYER 2 (follow the money / second revelation)
6. CLIMAX (the key confrontation, document, or proof)
7. RESOLUTION (what it means, systemic implications)
8. CTA + SIGN-OFF

For each section provide:
- section_name: string
- purpose: string (1 sentence)
- suggested_duration_seconds: int
- key_elements: string[] (3-5 bullet points)
- narration_tone: string

Output ONLY a valid JSON array of section objects.

```json
[
  {"section_name": "...", "purpose": "...", "suggested_duration_seconds": 30, "key_elements": ["..."], "narration_tone": "..."}
]
```
PROMPT;

        $systemPrompt = ptmd_ai_system_prompt();
        $aiResult     = openai_chat($systemPrompt, $prompt, 1200);

        if (empty($aiResult['content'])) {
            return ['ok' => false, 'sections' => [], 'generation_id' => 0, 'error' => 'AI returned no content'];
        }

        $rawContent = $aiResult['content'];
        $jsonString = $rawContent;
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $rawContent, $matches)) {
            $jsonString = $matches[1];
        }

        $sections = json_decode(trim($jsonString), true);
        if (!is_array($sections)) {
            $sections = [];
        }

        $model          = $aiResult['model']            ?? 'gpt-4o-mini';
        $promptTokens   = (int) ($aiResult['prompt_tokens']   ?? 0);
        $responseTokens = (int) ($aiResult['response_tokens'] ?? 0);

        $genId = save_ai_generation('script_outline_generation', $prompt, $rawContent, $model, $promptTokens, $responseTokens, $caseId);
        ptmd_record_ai_cost('script_outline_generation', $model, $promptTokens, $responseTokens, null, $genId);

        ptmd_emit_event(
            'content.script_outline.generated',
            'content_generation',
            'case',
            $caseId,
            ['sections_count' => count($sections), 'blueprint_id' => $blueprintId, 'generation_id' => $genId],
            null,
            ptmd_generate_trace_id(),
            null, null, null, null,
            'generated',
            'ai'
        );

        return ['ok' => true, 'sections' => $sections, 'generation_id' => $genId, 'error' => null];
    } catch (\Throwable $e) {
        error_log('[PTMD ContentGen] ptmd_generate_script_outline failed: ' . $e->getMessage());
        return ['ok' => false, 'sections' => [], 'generation_id' => 0, 'error' => $e->getMessage()];
    }
}

<?php

declare(strict_types=1);

/**
 * PTMD — Hook Service
 * Hook generation, scoring, classification, and Hook Lab retrieval system.
 */

require_once __DIR__ . '/EventTrackingService.php';
require_once __DIR__ . '/OptimizerService.php';

/**
 * Generate hook variants for a case using AI + scoring.
 *
 * @param int         $caseId
 * @param string      $platform  'all', 'tiktok', 'youtube', 'instagram_reels', etc.
 * @param string|null $hookType  Force a specific hook type; null = auto-select via optimizer
 * @param array       $context   Extra context for optimizer and scoring
 * @param int|null    $userId
 * @return array ['ok'=>bool, 'hooks'=>array, 'error'=>string|null]
 */
function ptmd_hook_generate(
    int $caseId,
    string $platform = 'all',
    ?string $hookType = null,
    array $context = [],
    ?int $userId = null
): array {
    $pdo = get_db();
    if (!$pdo) {
        return ['ok' => false, 'hooks' => [], 'error' => 'No database connection'];
    }

    try {
        $cStmt = $pdo->prepare('SELECT * FROM cases WHERE id = :id LIMIT 1');
        $cStmt->execute([':id' => $caseId]);
        $case = $cStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$case) {
            return ['ok' => false, 'hooks' => [], 'error' => "Case #{$caseId} not found"];
        }

        // Load recent active trend clusters
        $tStmt = $pdo->prepare(
            'SELECT * FROM trend_clusters
              WHERE status = "active" AND trend_score > 60
              ORDER BY trend_score DESC
              LIMIT 5'
        );
        $tStmt->execute();
        $trendClusters = $tStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Auto-select hook type if not specified
        $resolvedHookType = $hookType ?? ptmd_optimizer_select_hook_class(
            array_merge(ptmd_optimizer_load_case_context($caseId, $platform), $context)
        );

        $trendContext = array_map(fn($c) => ['label' => $c['label'], 'score' => $c['trend_score']], $trendClusters);
        $prompt       = ptmd_hook_build_prompt($case, $resolvedHookType, $platform, $trendContext);

        $systemPrompt = ptmd_ai_system_prompt();
        $aiResult     = openai_chat($systemPrompt, $prompt, 1200);

        if (empty($aiResult['content'])) {
            return ['ok' => false, 'hooks' => [], 'error' => 'AI returned no content'];
        }

        $traceId      = ptmd_generate_trace_id();
        $savedHooks   = [];
        $rawContent   = $aiResult['content'];

        // Extract JSON from response
        $jsonString = $rawContent;
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $rawContent, $matches)) {
            $jsonString = $matches[1];
        }

        $variants = json_decode(trim($jsonString), true);
        if (!is_array($variants)) {
            $variants = [['hook_text' => $rawContent, 'short_hook_text' => '', 'hook_angle' => '', 'explanation' => '']];
        }

        $promptTokens   = (int) ($aiResult['prompt_tokens']   ?? 0);
        $responseTokens = (int) ($aiResult['response_tokens'] ?? 0);
        $model          = $aiResult['model'] ?? 'gpt-4o-mini';

        $genId = save_ai_generation('hook_generation', $prompt, $rawContent, $model, $promptTokens, $responseTokens, $caseId);
        ptmd_record_ai_cost('hook_generation', $model, $promptTokens, $responseTokens, null, $genId);

        foreach ($variants as $variant) {
            $hookText      = (string) ($variant['hook_text']       ?? '');
            $shortHookText = (string) ($variant['short_hook_text'] ?? '');
            $hookAngle     = (string) ($variant['hook_angle']      ?? '');
            $explanation   = (string) ($variant['explanation']     ?? '');

            if (empty($hookText)) {
                continue;
            }

            $scores = ptmd_hook_score_variant($hookText, $resolvedHookType, $context);

            $hStmt = $pdo->prepare(
                'INSERT INTO hooks
                    (case_id, hook_type, hook_text, short_hook_text, hook_angle, explanation,
                     platform, trend_alignment_score, novelty_score, clarity_score,
                     curiosity_score, tension_score, expected_retention_score, confidence_score,
                     status, generation_id, trace_id, created_at)
                 VALUES
                    (:case_id, :hook_type, :hook_text, :short_hook_text, :hook_angle, :explanation,
                     :platform, :trend_alignment_score, :novelty_score, :clarity_score,
                     :curiosity_score, :tension_score, :expected_retention_score, :confidence_score,
                     :status, :generation_id, :trace_id, NOW())'
            );
            $hStmt->execute([
                ':case_id'                  => $caseId,
                ':hook_type'                => $resolvedHookType,
                ':hook_text'                => $hookText,
                ':short_hook_text'          => $shortHookText,
                ':hook_angle'               => $hookAngle,
                ':explanation'              => $explanation,
                ':platform'                 => $platform,
                ':trend_alignment_score'    => $scores['trend_alignment_score'],
                ':novelty_score'            => $scores['novelty_score'],
                ':clarity_score'            => $scores['clarity_score'],
                ':curiosity_score'          => $scores['curiosity_score'],
                ':tension_score'            => $scores['tension_score'],
                ':expected_retention_score' => $scores['expected_retention_score'],
                ':confidence_score'         => $scores['confidence_score'],
                ':status'                   => 'draft',
                ':generation_id'            => $genId,
                ':trace_id'                 => $traceId,
            ]);
            $hookId = (int) $pdo->lastInsertId();

            // Save hook variants (opener types)
            foreach (['longform_opener', 'shortform_opener', 'caption_opener'] as $openerType) {
                $openerText = match ($openerType) {
                    'longform_opener'  => $hookText,
                    'shortform_opener' => $shortHookText ?: mb_substr($hookText, 0, 100),
                    'caption_opener'   => mb_substr($hookText, 0, 150),
                };

                $ovStmt = $pdo->prepare(
                    'INSERT INTO hook_variants (hook_id, variant_type, opener_text, platform, created_at)
                     VALUES (:hook_id, :variant_type, :opener_text, :platform, NOW())'
                );
                $ovStmt->execute([
                    ':hook_id'      => $hookId,
                    ':variant_type' => $openerType,
                    ':opener_text'  => $openerText,
                    ':platform'     => $platform,
                ]);
            }

            ptmd_emit_event(
                'hook.generated',
                'hooks',
                'hook',
                $hookId,
                ['case_id' => $caseId, 'hook_type' => $resolvedHookType, 'platform' => $platform,
                 'confidence' => $scores['confidence_score']],
                $userId,
                $traceId,
                null, null, null,
                $scores['confidence_score'] / 100,
                'draft',
                'ai'
            );

            $savedHooks[] = array_merge(
                ['id' => $hookId, 'hook_text' => $hookText, 'short_hook_text' => $shortHookText,
                 'hook_type' => $resolvedHookType, 'platform' => $platform, 'status' => 'draft'],
                $scores
            );
        }

        return ['ok' => true, 'hooks' => $savedHooks, 'error' => null];
    } catch (\Throwable $e) {
        error_log('[PTMD Hooks] ptmd_hook_generate failed: ' . $e->getMessage());
        return ['ok' => false, 'hooks' => [], 'error' => $e->getMessage()];
    }
}

/**
 * Build the AI prompt for hook generation.
 *
 * @param array  $case         Case row from DB
 * @param string $hookType     PTMD hook type constant
 * @param string $platform     Target platform
 * @param array  $trendContext Array of ['label'=>string, 'score'=>float]
 * @return string
 */
function ptmd_hook_build_prompt(array $case, string $hookType, string $platform, array $trendContext): string
{
    $title       = $case['title']       ?? 'Untitled';
    $description = $case['description'] ?? $case['summary'] ?? '';
    $trendLines  = '';

    if (!empty($trendContext)) {
        $trendLines = "\n\nACTIVE TRENDS (by score):\n";
        foreach ($trendContext as $t) {
            $trendLines .= '- ' . ($t['label'] ?? '') . ' (score: ' . ($t['score'] ?? 0) . ")\n";
        }
    }

    return <<<PROMPT
You are a PTMD content strategist. PTMD produces investigative, evidence-driven, cinematic documentary content.
Tone: sharp, direct, no corporate safety, no generic phrasing. Every hook must feel like a revelation.

CASE TITLE: {$title}
CASE DESCRIPTION: {$description}
TARGET PLATFORM: {$platform}
HOOK TYPE: {$hookType}
{$trendLines}

Generate exactly 3 hook variants using the "{$hookType}" hook type.

For each variant produce:
- hook_text: Full hook (up to 280 chars), direct and provocative
- short_hook_text: Compressed version (max 100 chars), punchy
- hook_angle: The specific investigative angle (1 sentence)
- explanation: Why this hook works for PTMD and this case (1-2 sentences)

RULES:
- No passive voice. No "did you know". No "you won't believe".
- Must feel journalistically credible and cinematically urgent.
- If hook type is shock_contradiction: open with the contradiction directly.
- If hook type is data_alarm: lead with the specific number or stat.
- If hook type is curiosity: create an information gap, not a clickbait tease.

Output ONLY a valid JSON array of 3 objects. No markdown prose outside the JSON block.

```json
[
  {"hook_text": "...", "short_hook_text": "...", "hook_angle": "...", "explanation": "..."},
  {"hook_text": "...", "short_hook_text": "...", "hook_angle": "...", "explanation": "..."},
  {"hook_text": "...", "short_hook_text": "...", "hook_angle": "...", "explanation": "..."}
]
```
PROMPT;
}

/**
 * Score a single hook variant against context.
 *
 * @param string $hookText
 * @param string $hookType
 * @param array  $context  May include trend_alignment, novelty, etc. (0-100)
 * @return array All 7 score fields + confidence_score
 */
function ptmd_hook_score_variant(string $hookText, string $hookType, array $context): array
{
    $len = mb_strlen($hookText);

    // Heuristic scoring based on text signals and context
    $clarityScore   = $len > 20 && $len < 200 ? 70.0 : 50.0;
    $clarityScore  += substr_count($hookText, '?') > 0 ? 5.0 : 0.0;
    $clarityScore   = min(100.0, $clarityScore);

    $curiosityScore  = 60.0;
    $curiosityScore += (float) ($context['curiosity'] ?? 0) * 0.3;
    $curiosityScore  = min(100.0, $curiosityScore);

    $tensionScore  = 55.0;
    $tensionScore += (float) ($context['urgency'] ?? 0) * 0.2;
    $tensionScore  = min(100.0, $tensionScore);

    $trendScore = (float) ($context['trend_alignment'] ?? 50.0);
    $noveltyScore = (float) ($context['novelty'] ?? 60.0);

    // Boost scores for specific hook types
    if ($hookType === 'shock_contradiction') {
        $tensionScore  = min(100.0, $tensionScore + 15.0);
        $curiosityScore = min(100.0, $curiosityScore + 10.0);
    } elseif ($hookType === 'data_alarm') {
        $clarityScore = min(100.0, $clarityScore + 10.0);
        // data_alarm gets novelty boost if the hook contains numbers
        if (preg_match('/\d/', $hookText)) {
            $noveltyScore = min(100.0, $noveltyScore + 10.0);
        }
    } elseif ($hookType === 'curiosity') {
        $curiosityScore = min(100.0, $curiosityScore + 10.0);
    }

    $retentionScore = round((0.3 * $clarityScore) + (0.4 * $curiosityScore) + (0.3 * $tensionScore), 2);
    $confidence     = round(($trendScore * 0.3) + ($clarityScore * 0.3) + ($retentionScore * 0.4), 2);

    return [
        'trend_alignment_score'    => round($trendScore, 2),
        'novelty_score'            => round($noveltyScore, 2),
        'clarity_score'            => round($clarityScore, 2),
        'curiosity_score'          => round($curiosityScore, 2),
        'tension_score'            => round($tensionScore, 2),
        'expected_retention_score' => $retentionScore,
        'confidence_score'         => $confidence,
    ];
}

/**
 * Approve or reject a hook.
 *
 * @param int      $hookId
 * @param bool     $approved
 * @param int|null $userId
 * @return bool
 */
function ptmd_hook_approve(int $hookId, bool $approved, ?int $userId = null): bool
{
    $pdo = get_db();
    if (!$pdo) {
        return false;
    }

    try {
        $status = $approved ? 'approved' : 'rejected';
        $stmt   = $pdo->prepare(
            'UPDATE hooks
                SET status = :status, approved_by = :approved_by, approved_at = NOW()
              WHERE id = :id'
        );
        $stmt->execute([':status' => $status, ':approved_by' => $userId, ':id' => $hookId]);

        $eventName = $approved ? 'hook.approved' : 'hook.rejected';
        ptmd_emit_event($eventName, 'hooks', 'hook', $hookId,
            ['status' => $status], $userId, null, null, null, $status, null, $status, 'human');

        return true;
    } catch (\Throwable $e) {
        error_log('[PTMD Hooks] ptmd_hook_approve failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get all hooks for a case, with variant and performance data.
 *
 * @param int         $caseId
 * @param string|null $platform  Filter by platform; null = all platforms
 * @return array
 */
function ptmd_hook_get_for_case(int $caseId, ?string $platform = null): array
{
    $pdo = get_db();
    if (!$pdo) {
        return [];
    }

    try {
        $sql    = 'SELECT h.* FROM hooks h WHERE h.case_id = :case_id';
        $params = [':case_id' => $caseId];

        if ($platform !== null) {
            $sql            .= ' AND h.platform = :platform';
            $params[':platform'] = $platform;
        }

        $sql .= ' ORDER BY h.confidence_score DESC, h.created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $hooks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($hooks as &$hook) {
            $vStmt = $pdo->prepare(
                'SELECT * FROM hook_variants WHERE hook_id = :hook_id ORDER BY variant_type'
            );
            $vStmt->execute([':hook_id' => $hook['id']]);
            $hook['variants'] = $vStmt->fetchAll(\PDO::FETCH_ASSOC);

            $pStmt = $pdo->prepare(
                'SELECT * FROM hook_performance WHERE hook_id = :hook_id ORDER BY recorded_at DESC LIMIT 5'
            );
            $pStmt->execute([':hook_id' => $hook['id']]);
            $hook['performance'] = $pStmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        unset($hook);

        return $hooks;
    } catch (\Throwable $e) {
        error_log('[PTMD Hooks] ptmd_hook_get_for_case failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get Hook Lab summary data — aggregate performance by hook type.
 * Used by the Hook Lab admin page.
 *
 * @param string|null $platform
 * @param string|null $hookType
 * @param int         $days
 * @return array Average scores by hook type, win rates, accepted/rejected counts, platform comparisons
 */
function ptmd_hook_lab_summary(
    ?string $platform = null,
    ?string $hookType = null,
    int $days = 30
): array {
    $pdo = get_db();
    if (!$pdo) {
        return [];
    }

    try {
        $params = [];
        $where  = ['h.created_at >= NOW() - INTERVAL :days DAY'];
        $params[':days'] = $days;

        if ($platform !== null) {
            $where[]           = 'h.platform = :platform';
            $params[':platform'] = $platform;
        }
        if ($hookType !== null) {
            $where[]           = 'h.hook_type = :hook_type';
            $params[':hook_type'] = $hookType;
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT
                h.hook_type,
                h.platform,
                COUNT(*) AS total_hooks,
                SUM(CASE WHEN h.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                SUM(CASE WHEN h.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
                AVG(h.trend_alignment_score)    AS avg_trend_alignment,
                AVG(h.novelty_score)            AS avg_novelty,
                AVG(h.clarity_score)            AS avg_clarity,
                AVG(h.curiosity_score)          AS avg_curiosity,
                AVG(h.tension_score)            AS avg_tension,
                AVG(h.expected_retention_score) AS avg_expected_retention,
                AVG(h.confidence_score)         AS avg_confidence
             FROM hooks h
             WHERE {$whereClause}
             GROUP BY h.hook_type, h.platform
             ORDER BY avg_confidence DESC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $total = (int) $row['total_hooks'];
            $row['win_rate'] = $total > 0
                ? round((int) $row['approved_count'] / $total * 100, 2)
                : 0.0;
        }
        unset($row);

        return $rows;
    } catch (\Throwable $e) {
        error_log('[PTMD Hooks] ptmd_hook_lab_summary failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Link hook performance snapshot from a posted queue item.
 *
 * @param int    $hookId
 * @param int    $queueId
 * @param string $platform
 * @param array  $metrics  Keys: views, likes, shares, comments, watch_time_pct, etc.
 * @return bool
 */
function ptmd_hook_record_performance(int $hookId, int $queueId, string $platform, array $metrics): bool
{
    $pdo = get_db();
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO hook_performance
                (hook_id, queue_id, platform, metrics, recorded_at)
             VALUES
                (:hook_id, :queue_id, :platform, :metrics, NOW())'
        );
        $stmt->execute([
            ':hook_id'  => $hookId,
            ':queue_id' => $queueId,
            ':platform' => $platform,
            ':metrics'  => json_encode($metrics, JSON_UNESCAPED_UNICODE),
        ]);

        ptmd_emit_event(
            'hook.performance.recorded',
            'hooks',
            'hook',
            $hookId,
            array_merge($metrics, ['queue_id' => $queueId, 'platform' => $platform]),
            null, null, null, null, null, null, 'recorded', 'system'
        );

        return true;
    } catch (\Throwable $e) {
        error_log('[PTMD Hooks] ptmd_hook_record_performance failed: ' . $e->getMessage());
        return false;
    }
}

<?php

declare(strict_types=1);

/**
 * PTMD — Optimizer Service
 * AI content optimizer with weighted scoring, multi-variant generation, and full explainability.
 */

require_once __DIR__ . '/EventTrackingService.php';

/**
 * Run a full optimizer pass for a target entity.
 * Returns the full run result with all variants, factors, and decision.
 *
 * @param string   $targetType  'case', 'clip', 'hook'
 * @param int      $targetId
 * @param string   $platform    'all', 'tiktok', 'youtube', 'instagram_reels', etc.
 * @param string   $cohort      Audience cohort identifier
 * @param array    $context     Optional override context values
 * @param int|null $userId
 * @return array ['ok'=>bool, 'run_id'=>int|null, 'score'=>float, 'confidence'=>float, 'decision'=>string,
 *               'variants'=>array, 'factors'=>array, 'requires_approval'=>bool, 'error'=>string|null]
 */
function ptmd_optimizer_run(
    string $targetType,
    int $targetId,
    string $platform = 'all',
    string $cohort = 'general',
    array $context = [],
    ?int $userId = null
): array {
    $traceId = ptmd_generate_trace_id();

    $loadedContext = [];
    if ($targetType === 'case') {
        $loadedContext = ptmd_optimizer_load_case_context($targetId, $platform);
    }
    $context = array_merge($loadedContext, $context);

    // Build factor values from context, defaulting to neutral 50
    $factorValues = [
        'trend_alignment' => (float) ($context['trend_alignment'] ?? 50),
        'audience_match'  => (float) ($context['audience_match']  ?? 50),
        'retention_pred'  => (float) ($context['retention_pred']  ?? 50),
        'novelty'         => (float) ($context['novelty']         ?? 50),
        'brand_fit'       => (float) ($context['brand_fit']       ?? 50),
        'platform_fit'    => (float) ($context['platform_fit']    ?? 50),
        'timing_fit'      => (float) ($context['timing_fit']      ?? 50),
        'safety_margin'   => (float) ($context['safety_margin']   ?? 50),
    ];

    $scored     = ptmd_optimizer_score_factors($factorValues, $platform);
    $score      = $scored['score'];
    $confidence = ptmd_optimizer_confidence($factorValues, $context);

    if ($score >= 78 && $confidence >= 70) {
        $decision         = 'auto_recommend';
        $requiresApproval = false;
    } elseif ($score >= 60 && $confidence >= 50) {
        $decision         = 'human_review';
        $requiresApproval = true;
    } else {
        $decision         = 'fallback';
        $requiresApproval = true;
    }

    $pdo = get_db();
    if (!$pdo) {
        return ['ok' => false, 'run_id' => null, 'score' => $score, 'confidence' => $confidence,
                'decision' => $decision, 'variants' => [], 'factors' => [], 'requires_approval' => $requiresApproval,
                'error' => 'No database connection'];
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO optimizer_runs
                (target_type, target_id, platform, cohort, score, confidence,
                 decision, requires_approval, context_snapshot, trace_id, run_by, created_at)
             VALUES
                (:target_type, :target_id, :platform, :cohort, :score, :confidence,
                 :decision, :requires_approval, :context_snapshot, :trace_id, :run_by, NOW())'
        );
        $stmt->execute([
            ':target_type'       => $targetType,
            ':target_id'         => $targetId,
            ':platform'          => $platform,
            ':cohort'            => $cohort,
            ':score'             => round($score, 4),
            ':confidence'        => round($confidence, 4),
            ':decision'          => $decision,
            ':requires_approval' => $requiresApproval ? 1 : 0,
            ':context_snapshot'  => json_encode($context, JSON_UNESCAPED_UNICODE),
            ':trace_id'          => $traceId,
            ':run_by'            => $userId,
        ]);
        $runId = (int) $pdo->lastInsertId();

        // Insert factor rows
        $factorRows = [];
        foreach ($scored['factors'] as $factor) {
            $fStmt = $pdo->prepare(
                'INSERT INTO optimizer_factors
                    (run_id, factor_name, factor_value, weight, contribution, created_at)
                 VALUES
                    (:run_id, :factor_name, :factor_value, :weight, :contribution, NOW())'
            );
            $fStmt->execute([
                ':run_id'       => $runId,
                ':factor_name'  => $factor['factor_name'],
                ':factor_value' => $factor['factor_value'],
                ':weight'       => $factor['weight'],
                ':contribution' => $factor['contribution'],
            ]);
            $factorRows[] = array_merge($factor, ['id' => (int) $pdo->lastInsertId(), 'run_id' => $runId]);
        }

        // Generate 3 variants
        $hookClass    = ptmd_optimizer_select_hook_class($context);
        $variantTypes = ['title', 'hook', 'caption'];
        $variantRows  = [];

        foreach ($variantTypes as $vType) {
            $vStmt = $pdo->prepare(
                'INSERT INTO optimizer_variants
                    (run_id, variant_type, hook_class, platform, content_suggestion,
                     variant_score, status, created_at)
                 VALUES
                    (:run_id, :variant_type, :hook_class, :platform, :content_suggestion,
                     :variant_score, :status, NOW())'
            );
            $suggestion = "Optimizer-suggested {$vType} for {$targetType} #{$targetId} [{$platform}]";
            $vStmt->execute([
                ':run_id'             => $runId,
                ':variant_type'       => $vType,
                ':hook_class'         => $hookClass,
                ':platform'           => $platform,
                ':content_suggestion' => $suggestion,
                ':variant_score'      => round($score, 4),
                ':status'             => 'pending_review',
            ]);
            $variantRows[] = [
                'id'                 => (int) $pdo->lastInsertId(),
                'run_id'             => $runId,
                'variant_type'       => $vType,
                'hook_class'         => $hookClass,
                'platform'           => $platform,
                'content_suggestion' => $suggestion,
                'variant_score'      => round($score, 4),
                'status'             => 'pending_review',
            ];
        }

        ptmd_emit_event(
            'optimizer.run.completed',
            'optimizer',
            $targetType,
            $targetId,
            ['score' => $score, 'confidence' => $confidence, 'decision' => $decision, 'platform' => $platform],
            $userId,
            $traceId,
            null,
            null,
            null,
            $confidence,
            $decision,
            'system'
        );

        return [
            'ok'               => true,
            'run_id'           => $runId,
            'score'            => round($score, 4),
            'confidence'       => round($confidence, 4),
            'decision'         => $decision,
            'variants'         => $variantRows,
            'factors'          => $factorRows,
            'requires_approval' => $requiresApproval,
            'error'            => null,
        ];
    } catch (\Throwable $e) {
        error_log('[PTMD Optimizer] ptmd_optimizer_run failed: ' . $e->getMessage());
        return ['ok' => false, 'run_id' => null, 'score' => $score, 'confidence' => $confidence,
                'decision' => $decision, 'variants' => [], 'factors' => [], 'requires_approval' => $requiresApproval,
                'error' => $e->getMessage()];
    }
}

/**
 * Score a single set of factors using the weighted formula.
 *
 * @param array  $factorValues Keys: trend_alignment, audience_match, retention_pred,
 *                             novelty, brand_fit, platform_fit, timing_fit, safety_margin (each 0-100)
 * @param string $platform
 * @return array ['score' => float, 'factors' => array of factor rows]
 */
function ptmd_optimizer_score_factors(array $factorValues, string $platform = 'all'): array
{
    $weights = [
        'trend_alignment' => 0.22,
        'audience_match'  => 0.20,
        'retention_pred'  => 0.18,
        'novelty'         => 0.12,
        'brand_fit'       => 0.10,
        'platform_fit'    => 0.08,
        'timing_fit'      => 0.06,
        'safety_margin'   => 0.04,
    ];

    $score   = 0.0;
    $factors = [];

    foreach ($weights as $name => $weight) {
        $value        = (float) ($factorValues[$name] ?? 50);
        $contribution = $weight * $value;
        $score       += $contribution;

        $factors[] = [
            'factor_name'  => $name,
            'factor_value' => round($value, 4),
            'weight'       => $weight,
            'contribution' => round($contribution, 4),
        ];
    }

    return ['score' => round($score, 4), 'factors' => $factors];
}

/**
 * Calculate confidence from factor scores and context signals.
 *
 * @param array $factorValues
 * @param array $context      May include: data_quality, signal_consensus, historical_similarity, uncertainty_penalty
 * @return float 0-100
 */
function ptmd_optimizer_confidence(array $factorValues, array $context = []): float
{
    $dataQuality           = (float) ($context['data_quality']           ?? 60);
    $signalConsensus       = (float) ($context['signal_consensus']       ?? 60);
    $historicalSimilarity  = (float) ($context['historical_similarity']  ?? 50);
    $uncertaintyPenalty    = (float) ($context['uncertainty_penalty']    ?? 10);

    $raw = (0.5 * $dataQuality) + (0.3 * $signalConsensus) + (0.2 * $historicalSimilarity) - $uncertaintyPenalty;

    return round(max(0.0, min(100.0, $raw)), 4);
}

/**
 * Select the best hook class for a given context.
 *
 * @param array $context Keys: urgency, trend_freshness, controversy, safety,
 *                       humor_compatibility, evidence_strength (all 0-100)
 * @return string hook_type matching hooks.hook_type ENUM
 */
function ptmd_optimizer_select_hook_class(array $context): string
{
    $urgency            = (float) ($context['urgency']            ?? 0);
    $trendFreshness     = (float) ($context['trend_freshness']    ?? 0);
    $controversy        = (float) ($context['controversy']        ?? 0);
    $safety             = (float) ($context['safety']             ?? 50);
    $humorCompatibility = (float) ($context['humor_compatibility'] ?? 0);
    $evidenceStrength   = (float) ($context['evidence_strength']  ?? 0);

    if ($urgency > 75 && $trendFreshness > 70) {
        return 'shock_contradiction';
    }
    if ($controversy > 65 && $safety >= 50) {
        return 'authority_conflict';
    }
    if ($humorCompatibility > 70 && $safety >= 60) {
        return 'humor_skepticism';
    }
    if ($evidenceStrength > 70) {
        return 'data_alarm';
    }

    return 'curiosity';
}

/**
 * Get the optimizer run with all its factors and variants.
 *
 * @param int $runId
 * @return array|null
 */
function ptmd_optimizer_get_run(int $runId): ?array
{
    $pdo = get_db();
    if (!$pdo) {
        return null;
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM optimizer_runs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $runId]);
        $run = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$run) {
            return null;
        }

        $fStmt = $pdo->prepare('SELECT * FROM optimizer_factors WHERE run_id = :run_id ORDER BY weight DESC');
        $fStmt->execute([':run_id' => $runId]);
        $run['factors'] = $fStmt->fetchAll(\PDO::FETCH_ASSOC);

        $vStmt = $pdo->prepare('SELECT * FROM optimizer_variants WHERE run_id = :run_id ORDER BY variant_type');
        $vStmt->execute([':run_id' => $runId]);
        $run['variants'] = $vStmt->fetchAll(\PDO::FETCH_ASSOC);

        return $run;
    } catch (\Throwable $e) {
        error_log('[PTMD Optimizer] ptmd_optimizer_get_run failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Mark a variant as accepted or rejected by a human.
 *
 * @param int         $variantId
 * @param bool        $accepted
 * @param string|null $rejectionReason
 * @param int|null    $userId
 * @return bool
 */
function ptmd_optimizer_review_variant(
    int $variantId,
    bool $accepted,
    ?string $rejectionReason = null,
    ?int $userId = null
): bool {
    $pdo = get_db();
    if (!$pdo) {
        return false;
    }

    try {
        $status = $accepted ? 'accepted' : 'rejected';
        $stmt   = $pdo->prepare(
            'UPDATE optimizer_variants
                SET status = :status,
                    rejection_reason = :rejection_reason,
                    reviewed_by = :reviewed_by,
                    reviewed_at = NOW()
              WHERE id = :id'
        );
        $stmt->execute([
            ':status'           => $status,
            ':rejection_reason' => $rejectionReason,
            ':reviewed_by'      => $userId,
            ':id'               => $variantId,
        ]);

        $eventName = $accepted ? 'optimizer.variant.accepted' : 'optimizer.variant.rejected';
        ptmd_emit_event($eventName, 'optimizer', 'optimizer_variant', $variantId,
            ['rejection_reason' => $rejectionReason], $userId, null, null, null, null, null, $status, 'human');

        return true;
    } catch (\Throwable $e) {
        error_log('[PTMD Optimizer] ptmd_optimizer_review_variant failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Load context for optimizer from DB for a case.
 * Pulls case data, recent trend clusters, platform performance history.
 *
 * @param int    $caseId
 * @param string $platform
 * @return array
 */
function ptmd_optimizer_load_case_context(int $caseId, string $platform = 'all'): array
{
    $context = [];
    $pdo     = get_db();
    if (!$pdo) {
        return $context;
    }

    try {
        $cStmt = $pdo->prepare('SELECT * FROM cases WHERE id = :id LIMIT 1');
        $cStmt->execute([':id' => $caseId]);
        $case = $cStmt->fetch(\PDO::FETCH_ASSOC);
        if ($case) {
            $context['case_title']       = $case['title'] ?? '';
            $context['case_status']      = $case['status'] ?? '';
            $context['brand_fit']        = 60.0; // Baseline; real implementations score this
            $context['safety_margin']    = 70.0;
        }

        // Load recent active trend clusters
        $tStmt = $pdo->prepare(
            'SELECT trend_score, label FROM trend_clusters
              WHERE status = "active"
              ORDER BY trend_score DESC
              LIMIT 5'
        );
        $tStmt->execute();
        $clusters = $tStmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!empty($clusters)) {
            $avgTrend                    = array_sum(array_column($clusters, 'trend_score')) / count($clusters);
            $context['trend_alignment']  = min(100.0, $avgTrend);
            $context['trend_freshness']  = min(100.0, $avgTrend);
            $context['signal_consensus'] = min(100.0, $avgTrend * 0.9);
        }

        // Historical performance for platform_fit and timing_fit
        $pStmt = $pdo->prepare(
            'SELECT AVG(views) AS avg_views
               FROM social_post_logs
              WHERE platform = :platform AND status = "posted"
              ORDER BY id DESC
              LIMIT 20'
        );
        $pStmt->execute([':platform' => $platform]);
        $perf = $pStmt->fetch(\PDO::FETCH_ASSOC);
        if ($perf && $perf['avg_views'] !== null) {
            $context['platform_fit']           = min(100.0, (float) $perf['avg_views'] / 10);
            $context['historical_similarity']  = 55.0;
        }

        $context['data_quality']   = !empty($clusters) ? 70.0 : 40.0;
        $context['audience_match'] = 55.0;
        $context['retention_pred'] = 55.0;
        $context['novelty']        = 60.0;
        $context['timing_fit']     = 50.0;
    } catch (\Throwable $e) {
        error_log('[PTMD Optimizer] ptmd_optimizer_load_case_context failed: ' . $e->getMessage());
    }

    return $context;
}

<?php

declare(strict_types=1);

/**
 * PTMD — Trend Intake Service
 * Trend signal ingestion, normalization, deduplication, and cluster management.
 */

require_once __DIR__ . '/EventTrackingService.php';
require_once __DIR__ . '/LifecycleService.php';

/**
 * Ingest a new trend signal. Deduplicates automatically.
 *
 * @param array    $data   Keys: normalized_topic (required), source_id, external_ref, raw_json,
 *                         freshness_score, cultural_score, brand_fit_score, sensitivity_score,
 *                         doc_potential_score, clip_potential_score, humor_score,
 *                         platform_velocity_score, explanation_text
 * @param int|null $userId
 * @return array ['ok'=>bool, 'signal_id'=>int|null, 'duplicate'=>bool, 'error'=>string|null]
 */
function ptmd_trend_ingest(array $data, ?int $userId = null): array
{
    if (empty($data['normalized_topic'])) {
        return ['ok' => false, 'signal_id' => null, 'duplicate' => false, 'error' => 'normalized_topic is required'];
    }

    $normalizedTopic = ptmd_trend_normalize_topic($data['normalized_topic']);
    $dedupeHash      = ptmd_trend_dedupe_hash($normalizedTopic);

    $pdo = get_db();
    if (!$pdo) {
        return ['ok' => false, 'signal_id' => null, 'duplicate' => false, 'error' => 'No database connection'];
    }

    try {
        // Check for duplicate
        $checkStmt = $pdo->prepare(
            'SELECT id, trend_score FROM trend_signals WHERE dedupe_hash = :hash LIMIT 1'
        );
        $checkStmt->execute([':hash' => $dedupeHash]);
        $existing = $checkStmt->fetch(\PDO::FETCH_ASSOC);

        $freshness        = (float) ($data['freshness_score']        ?? 50);
        $cultural         = (float) ($data['cultural_score']         ?? 50);
        $docPotential     = (float) ($data['doc_potential_score']    ?? 50);
        $brandFit         = (float) ($data['brand_fit_score']        ?? 50);
        $clipPotential    = (float) ($data['clip_potential_score']   ?? 50);
        $platformVelocity = (float) ($data['platform_velocity_score'] ?? 50);
        $sensitivity      = (float) ($data['sensitivity_score']      ?? 0);
        $humor            = (float) ($data['humor_score']            ?? 50);

        $trendScore = ptmd_trend_score_signal(
            $freshness, $cultural, $docPotential, $brandFit, $clipPotential, $platformVelocity, $sensitivity
        );

        if ($existing) {
            // Update if new score is higher
            if ($trendScore > (float) $existing['trend_score']) {
                $upStmt = $pdo->prepare(
                    'UPDATE trend_signals
                        SET trend_score = :trend_score,
                            freshness_score = :freshness,
                            cultural_score = :cultural,
                            doc_potential_score = :doc_potential,
                            brand_fit_score = :brand_fit,
                            clip_potential_score = :clip_potential,
                            platform_velocity_score = :platform_velocity,
                            sensitivity_score = :sensitivity,
                            humor_score = :humor,
                            updated_at = NOW()
                      WHERE id = :id'
                );
                $upStmt->execute([
                    ':trend_score'       => round($trendScore, 4),
                    ':freshness'         => $freshness,
                    ':cultural'          => $cultural,
                    ':doc_potential'     => $docPotential,
                    ':brand_fit'         => $brandFit,
                    ':clip_potential'    => $clipPotential,
                    ':platform_velocity' => $platformVelocity,
                    ':sensitivity'       => $sensitivity,
                    ':humor'             => $humor,
                    ':id'                => $existing['id'],
                ]);
            }
            return ['ok' => true, 'signal_id' => (int) $existing['id'], 'duplicate' => true, 'error' => null];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO trend_signals
                (normalized_topic, dedupe_hash, source_id, external_ref, raw_json,
                 freshness_score, cultural_score, doc_potential_score, brand_fit_score,
                 clip_potential_score, platform_velocity_score, sensitivity_score,
                 humor_score, trend_score, explanation_text, status, ingested_by, created_at)
             VALUES
                (:normalized_topic, :dedupe_hash, :source_id, :external_ref, :raw_json,
                 :freshness, :cultural, :doc_potential, :brand_fit,
                 :clip_potential, :platform_velocity, :sensitivity,
                 :humor, :trend_score, :explanation_text, :status, :ingested_by, NOW())'
        );
        $stmt->execute([
            ':normalized_topic' => $normalizedTopic,
            ':dedupe_hash'      => $dedupeHash,
            ':source_id'        => $data['source_id']        ?? null,
            ':external_ref'     => $data['external_ref']     ?? null,
            ':raw_json'         => $data['raw_json']         ?? null,
            ':freshness'        => $freshness,
            ':cultural'         => $cultural,
            ':doc_potential'    => $docPotential,
            ':brand_fit'        => $brandFit,
            ':clip_potential'   => $clipPotential,
            ':platform_velocity'=> $platformVelocity,
            ':sensitivity'      => $sensitivity,
            ':humor'            => $humor,
            ':trend_score'      => round($trendScore, 4),
            ':explanation_text' => $data['explanation_text'] ?? null,
            ':status'           => 'active',
            ':ingested_by'      => $userId,
        ]);
        $signalId = (int) $pdo->lastInsertId();

        ptmd_emit_event(
            'trend.signal.ingested',
            'trends',
            'trend_signal',
            $signalId,
            ['topic' => $normalizedTopic, 'trend_score' => $trendScore],
            $userId,
            null, null, null, null,
            $trendScore / 100,
            'active',
            'system'
        );

        return ['ok' => true, 'signal_id' => $signalId, 'duplicate' => false, 'error' => null];
    } catch (\Throwable $e) {
        error_log('[PTMD TrendIntake] ptmd_trend_ingest failed: ' . $e->getMessage());
        return ['ok' => false, 'signal_id' => null, 'duplicate' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Normalize a topic string for consistent deduplication.
 * Lowercase, trim, strip punctuation, sort words alphabetically.
 *
 * @param string $raw
 * @return string
 */
function ptmd_trend_normalize_topic(string $raw): string
{
    $lower   = strtolower(trim($raw));
    $clean   = preg_replace('/[^\p{L}\p{N}\s]/u', '', $lower);
    $clean   = preg_replace('/\s+/', ' ', trim($clean ?? $lower));
    $words   = explode(' ', $clean);
    sort($words);
    return implode(' ', array_filter($words));
}

/**
 * Generate a SHA-256 dedupe hash from a normalized topic.
 *
 * @param string $normalizedTopic
 * @return string
 */
function ptmd_trend_dedupe_hash(string $normalizedTopic): string
{
    return hash('sha256', $normalizedTopic);
}

/**
 * Calculate a trend signal score from component scores.
 * Result is clamped to 0-100.
 *
 * @param float $freshness
 * @param float $cultural
 * @param float $docPotential
 * @param float $brandFit
 * @param float $clipPotential
 * @param float $platformVelocity
 * @param float $sensitivity     Penalty applied (subtracted after weight)
 * @return float
 */
function ptmd_trend_score_signal(
    float $freshness,
    float $cultural,
    float $docPotential,
    float $brandFit,
    float $clipPotential,
    float $platformVelocity,
    float $sensitivity
): float {
    $score = (0.25 * $freshness)
           + (0.20 * $cultural)
           + (0.20 * $docPotential)
           + (0.15 * $brandFit)
           + (0.10 * $clipPotential)
           + (0.10 * $platformVelocity)
           - ($sensitivity * 0.2);

    return round(max(0.0, min(100.0, $score)), 4);
}

/**
 * Promote similar signals into a cluster.
 *
 * @param int[]  $signalIds
 * @param string $label
 * @param string $summary
 * @return array ['ok'=>bool, 'cluster_id'=>int|null, 'merged_count'=>int, 'error'=>string|null]
 */
function ptmd_trend_cluster_signals(array $signalIds, string $label, string $summary = ''): array
{
    if (empty($signalIds)) {
        return ['ok' => false, 'cluster_id' => null, 'merged_count' => 0, 'error' => 'No signal IDs provided'];
    }

    $pdo = get_db();
    if (!$pdo) {
        return ['ok' => false, 'cluster_id' => null, 'merged_count' => 0, 'error' => 'No database connection'];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($signalIds), '?'));
        $valStmt      = $pdo->prepare(
            "SELECT id, trend_score, freshness_score FROM trend_signals WHERE id IN ({$placeholders})"
        );
        $valStmt->execute($signalIds);
        $foundSignals = $valStmt->fetchAll(\PDO::FETCH_ASSOC);

        if (count($foundSignals) !== count($signalIds)) {
            return ['ok' => false, 'cluster_id' => null, 'merged_count' => 0, 'error' => 'Some signal IDs not found'];
        }

        $scores         = array_column($foundSignals, 'trend_score');
        $freshnessScores = array_column($foundSignals, 'freshness_score');
        $avgScore       = array_sum($scores) / count($scores);
        $maxFreshness   = max($freshnessScores);
        $signalCount    = count($signalIds);
        $shelfLifeHours = (int) site_setting('trend_cluster_shelf_life_hours', '72');

        $cStmt = $pdo->prepare(
            'INSERT INTO trend_clusters
                (label, summary, signal_count, trend_score, freshness_score,
                 status, expires_at, created_at)
             VALUES
                (:label, :summary, :signal_count, :trend_score, :freshness_score,
                 :status, NOW() + INTERVAL :shelf_life HOUR, NOW())'
        );
        $cStmt->execute([
            ':label'          => $label,
            ':summary'        => $summary,
            ':signal_count'   => $signalCount,
            ':trend_score'    => round($avgScore, 4),
            ':freshness_score'=> $maxFreshness,
            ':status'         => 'active',
            ':shelf_life'     => $shelfLifeHours,
        ]);
        $clusterId = (int) $pdo->lastInsertId();

        // Update all signals to point to this cluster
        $upParams   = array_merge([$clusterId], $signalIds);
        $upStmt     = $pdo->prepare(
            "UPDATE trend_signals
                SET cluster_id = ?, status = 'clustered'
              WHERE id IN ({$placeholders})"
        );
        $upStmt->execute($upParams);

        ptmd_emit_event(
            'trend.cluster.created',
            'trends',
            'trend_cluster',
            $clusterId,
            ['label' => $label, 'signal_count' => $signalCount, 'trend_score' => $avgScore],
            null,
            ptmd_generate_trace_id(),
            null, null, null,
            $avgScore / 100,
            'active',
            'system'
        );

        return ['ok' => true, 'cluster_id' => $clusterId, 'merged_count' => $signalCount, 'error' => null];
    } catch (\Throwable $e) {
        error_log('[PTMD TrendIntake] ptmd_trend_cluster_signals failed: ' . $e->getMessage());
        return ['ok' => false, 'cluster_id' => null, 'merged_count' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Get active trend clusters ordered by trend_score desc.
 *
 * @param int $limit
 * @return array
 */
function ptmd_trend_get_active_clusters(int $limit = 20): array
{
    $pdo = get_db();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM trend_clusters
              WHERE status = 'active'
                AND (expires_at IS NULL OR expires_at > NOW())
              ORDER BY trend_score DESC
              LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('[PTMD TrendIntake] ptmd_trend_get_active_clusters failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Promote a trend cluster to a case draft.
 *
 * @param int      $clusterId
 * @param array    $caseData   Optional overrides for title, description, etc.
 * @param int|null $userId
 * @return array ['ok'=>bool, 'case_id'=>int|null, 'error'=>string|null]
 */
function ptmd_trend_promote_to_case(int $clusterId, array $caseData = [], ?int $userId = null): array
{
    $pdo = get_db();
    if (!$pdo) {
        return ['ok' => false, 'case_id' => null, 'error' => 'No database connection'];
    }

    try {
        $clStmt = $pdo->prepare('SELECT * FROM trend_clusters WHERE id = :id LIMIT 1');
        $clStmt->execute([':id' => $clusterId]);
        $cluster = $clStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$cluster) {
            return ['ok' => false, 'case_id' => null, 'error' => "Cluster #{$clusterId} not found"];
        }

        $title       = $caseData['title']       ?? $cluster['label']   ?? 'Untitled Trend Case';
        $description = $caseData['description'] ?? $cluster['summary'] ?? '';
        $slug        = slugify($title) . '-' . time();

        $insStmt = $pdo->prepare(
            'INSERT INTO cases
                (title, slug, description, status, created_at, updated_at)
             VALUES
                (:title, :slug, :description, :status, NOW(), NOW())'
        );
        $insStmt->execute([
            ':title'       => $title,
            ':slug'        => $slug,
            ':description' => $description,
            ':status'      => 'draft',
        ]);
        $caseId = (int) $pdo->lastInsertId();

        $upStmt = $pdo->prepare(
            'UPDATE trend_clusters
                SET status = "promoted", promoted_case_id = :case_id, updated_at = NOW()
              WHERE id = :id'
        );
        $upStmt->execute([':case_id' => $caseId, ':id' => $clusterId]);

        ptmd_emit_event(
            'trend.cluster.promoted',
            'trends',
            'trend_cluster',
            $clusterId,
            ['case_id' => $caseId, 'title' => $title],
            $userId,
            ptmd_generate_trace_id(),
            null,
            'active',
            'promoted',
            null,
            'promoted',
            $userId ? 'human' : 'system'
        );

        ptmd_lifecycle_transition('case', $caseId, 'promoted_to_case', 'system', null, 'Promoted from trend cluster #' . $clusterId);

        return ['ok' => true, 'case_id' => $caseId, 'error' => null];
    } catch (\Throwable $e) {
        error_log('[PTMD TrendIntake] ptmd_trend_promote_to_case failed: ' . $e->getMessage());
        return ['ok' => false, 'case_id' => null, 'error' => $e->getMessage()];
    }
}

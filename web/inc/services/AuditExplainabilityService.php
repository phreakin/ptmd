<?php

declare(strict_types=1);

/**
 * PTMD — Audit & Explainability Service
 * Full audit trail, override history, and optimizer explainability.
 */

require_once __DIR__ . '/EventTrackingService.php';

/**
 * Write an audit trail entry (always use this for sensitive operations).
 * Never throws — swallows all exceptions.
 *
 * @param string      $eventType   e.g. 'case.deleted', 'hook.approved', 'override.applied'
 * @param string      $entityType  e.g. 'case', 'hook', 'clip'
 * @param int         $entityId
 * @param array       $details     Freeform context; include 'severity' => 'critical' to also error_log
 * @param int|null    $userId
 * @param string|null $traceId
 */
function ptmd_audit_write(
    string $eventType,
    string $entityType,
    int $entityId,
    array $details = [],
    ?int $userId = null,
    ?string $traceId = null
): void {
    try {
        if (($details['severity'] ?? '') === 'critical') {
            error_log('[PTMD Audit] CRITICAL ' . $eventType . ' on ' . $entityType . ' #' . $entityId
                . ' by user #' . ($userId ?? 0) . ': ' . json_encode($details));
        }

        ptmd_emit_event(
            $eventType,
            'audit',
            $entityType,
            $entityId,
            $details,
            $userId,
            $traceId,
            null,
            $details['before_state'] ?? null,
            $details['after_state']  ?? null,
            null,
            $details['status'] ?? null,
            $details['source'] ?? 'human'
        );
    } catch (\Throwable $e) {
        error_log('[PTMD Audit] ptmd_audit_write failed silently: ' . $e->getMessage());
    }
}

/**
 * Get full audit trail for an entity (from ptmd_events + content_state_transitions).
 * Returns combined, chronologically sorted array of events and state transitions.
 *
 * @param string $entityType
 * @param int    $entityId
 * @param int    $limit
 * @return array
 */
function ptmd_audit_trail(string $entityType, int $entityId, int $limit = 100): array
{
    $pdo = get_db();
    if (!$pdo) {
        return [];
    }

    $events      = [];
    $transitions = [];

    try {
        $eStmt = $pdo->prepare(
            'SELECT id, event_name, event_category, module, meta, user_id,
                    trace_id, before_state, after_state, status, source,
                    created_at, "event" AS record_type
               FROM ptmd_events
              WHERE object_type = :object_type AND object_id = :object_id
              ORDER BY created_at DESC
              LIMIT :lim'
        );
        $eStmt->bindValue(':object_type', $entityType);
        $eStmt->bindValue(':object_id', $entityId, \PDO::PARAM_INT);
        $eStmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $eStmt->execute();
        $events = $eStmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('[PTMD Audit] ptmd_audit_trail events query failed: ' . $e->getMessage());
    }

    try {
        $tStmt = $pdo->prepare(
            'SELECT id, from_state, to_state, actor_type, actor_id AS user_id,
                    reason, meta, transitioned_at AS created_at, "transition" AS record_type
               FROM content_state_transitions
              WHERE entity_type = :entity_type AND entity_id = :entity_id
              ORDER BY transitioned_at DESC
              LIMIT :lim'
        );
        $tStmt->bindValue(':entity_type', $entityType);
        $tStmt->bindValue(':entity_id', $entityId, \PDO::PARAM_INT);
        $tStmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $tStmt->execute();
        $transitions = $tStmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('[PTMD Audit] ptmd_audit_trail transitions query failed: ' . $e->getMessage());
    }

    $combined = array_merge($events, $transitions);

    usort($combined, static function (array $a, array $b): int {
        return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
    });

    return array_slice($combined, 0, $limit);
}

/**
 * Get the explainability record for an optimizer run.
 * Returns structured explanation with factors, confidence, and human review guidance.
 *
 * @param int $runId
 * @return array
 */
function ptmd_explain_optimizer_run(int $runId): array
{
    $pdo = get_db();
    if (!$pdo) {
        return [];
    }

    try {
        $rStmt = $pdo->prepare('SELECT * FROM optimizer_runs WHERE id = :id LIMIT 1');
        $rStmt->execute([':id' => $runId]);
        $run = $rStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$run) {
            return [];
        }

        $fStmt = $pdo->prepare('SELECT * FROM optimizer_factors WHERE run_id = :run_id ORDER BY weight DESC');
        $fStmt->execute([':run_id' => $runId]);
        $factors = $fStmt->fetchAll(\PDO::FETCH_ASSOC);

        $vStmt = $pdo->prepare('SELECT * FROM optimizer_variants WHERE run_id = :run_id');
        $vStmt->execute([':run_id' => $runId]);
        $variants = $vStmt->fetchAll(\PDO::FETCH_ASSOC);

        $context = is_string($run['context_snapshot'])
            ? (json_decode($run['context_snapshot'], true) ?? [])
            : ($run['context_snapshot'] ?? []);

        $confidenceBreakdown = [
            'data_quality'          => (float) ($context['data_quality']          ?? 60),
            'signal_consensus'      => (float) ($context['signal_consensus']       ?? 60),
            'historical_similarity' => (float) ($context['historical_similarity']  ?? 50),
        ];

        $score      = (float) ($run['score']      ?? 0);
        $confidence = (float) ($run['confidence'] ?? 0);

        $humanReviewReasons = [];
        if ($confidence < 70) {
            $humanReviewReasons[] = 'Confidence below 70%';
        }
        if ($score < 78) {
            $humanReviewReasons[] = 'Score below auto-recommend threshold (78)';
        }
        if ((bool) ($run['requires_approval'] ?? true)) {
            $humanReviewReasons[] = 'Flagged as requires_approval by optimizer';
        }

        $decisionReason = ptmd_explain_recommendation($run, $factors);

        return [
            'run'                      => $run,
            'factors'                  => $factors,
            'variants'                 => $variants,
            'decision_reason'          => $decisionReason,
            'confidence_breakdown'     => $confidenceBreakdown,
            'human_review_recommended' => !empty($humanReviewReasons),
            'human_review_reasons'     => $humanReviewReasons,
        ];
    } catch (\Throwable $e) {
        error_log('[PTMD Audit] ptmd_explain_optimizer_run failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Build a plain-language explanation string for why a recommendation was made.
 *
 * @param array $runData  Row from optimizer_runs
 * @param array $factors  Rows from optimizer_factors
 * @return string e.g. "Score: 72.4/100 (confidence: 63%). Strongest factors: ..."
 */
function ptmd_explain_recommendation(array $runData, array $factors): string
{
    $score      = round((float) ($runData['score']      ?? 0), 1);
    $confidence = round((float) ($runData['confidence'] ?? 0), 1);

    if (empty($factors)) {
        return "Score: {$score}/100 (confidence: {$confidence}%). No factor data available.";
    }

    // Sort by contribution descending
    $sorted = $factors;
    usort($sorted, static fn($a, $b) => $b['contribution'] <=> $a['contribution']);

    $strongest = array_slice($sorted, 0, 2);
    $weakest   = array_slice(array_reverse($sorted), 0, 1);

    $strongParts = array_map(
        fn($f) => $f['factor_name'] . ' (' . round((float) $f['contribution'], 1) . ' pts)',
        $strongest
    );
    $weakParts = array_map(
        fn($f) => $f['factor_name'] . ' (' . round((float) $f['contribution'], 1) . ' pts)',
        $weakest
    );

    $explanation  = "Score: {$score}/100 (confidence: {$confidence}%). ";
    $explanation .= 'Strongest factors: ' . implode(', ', $strongParts) . '. ';
    $explanation .= 'Weakest: ' . implode(', ', $weakParts) . '. ';

    if ($confidence < 70) {
        $explanation .= 'Human review recommended because confidence < 70%.';
    } elseif ($score < 78) {
        $explanation .= 'Human review recommended because score < 78.';
    } else {
        $explanation .= 'Auto-recommend eligible.';
    }

    return $explanation;
}

/**
 * Get override history for an entity.
 *
 * @param string $entityType
 * @param int    $entityId
 * @return array
 */
function ptmd_audit_override_history(string $entityType, int $entityId): array
{
    $pdo = get_db();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM override_actions
              WHERE entity_type = :entity_type AND entity_id = :entity_id
              ORDER BY created_at DESC'
        );
        $stmt->execute([':entity_type' => $entityType, ':entity_id' => $entityId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('[PTMD Audit] ptmd_audit_override_history failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Record a human override of an AI recommendation.
 *
 * @param string $entityType
 * @param int    $entityId
 * @param string $overrideType   e.g. 'editorial_note', 'score_override', 'title_override'
 * @param string|null $originalValue
 * @param string $overrideValue
 * @param string $reason
 * @param int    $userId
 * @return int   New override_actions ID, or 0 on failure
 */
function ptmd_audit_record_override(
    string $entityType,
    int $entityId,
    string $overrideType,
    ?string $originalValue,
    string $overrideValue,
    string $reason,
    int $userId
): int {
    $pdo = get_db();
    if (!$pdo) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO override_actions
                (entity_type, entity_id, override_type, original_value,
                 override_value, reason, overridden_by, created_at)
             VALUES
                (:entity_type, :entity_id, :override_type, :original_value,
                 :override_value, :reason, :overridden_by, NOW())'
        );
        $stmt->execute([
            ':entity_type'    => $entityType,
            ':entity_id'      => $entityId,
            ':override_type'  => $overrideType,
            ':original_value' => $originalValue,
            ':override_value' => $overrideValue,
            ':reason'         => $reason,
            ':overridden_by'  => $userId,
        ]);

        $overrideId = (int) $pdo->lastInsertId();

        ptmd_audit_write(
            'audit.override.recorded',
            $entityType,
            $entityId,
            [
                'override_type'  => $overrideType,
                'original_value' => $originalValue,
                'override_value' => $overrideValue,
                'reason'         => $reason,
            ],
            $userId
        );

        return $overrideId;
    } catch (\Throwable $e) {
        error_log('[PTMD Audit] ptmd_audit_record_override failed: ' . $e->getMessage());
        return 0;
    }
}

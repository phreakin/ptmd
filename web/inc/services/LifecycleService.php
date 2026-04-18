<?php

declare(strict_types=1);

/**
 * PTMD — Lifecycle Service
 * Full content lifecycle state machine for cases, clips, assets, and ideas.
 */

require_once __DIR__ . '/EventTrackingService.php';

const PTMD_LIFECYCLE_STATES = [
    'idea', 'scored', 'shortlisted', 'promoted_to_case', 'researching',
    'scripting', 'recording', 'editing', 'clipping', 'optimized',
    'awaiting_approval', 'approved', 'scheduled', 'published',
    'repost_ready', 'archived', 'failed', 'needs_revision',
];

// Allowed transitions: [from_state => [allowed to_states]]
const PTMD_LIFECYCLE_TRANSITION_MAP = [
    'idea'              => ['scored', 'shortlisted', 'archived'],
    'scored'            => ['shortlisted', 'promoted_to_case', 'archived'],
    'shortlisted'       => ['promoted_to_case', 'idea', 'archived'],
    'promoted_to_case'  => ['researching', 'needs_revision'],
    'researching'       => ['scripting', 'needs_revision', 'archived'],
    'scripting'         => ['recording', 'needs_revision'],
    'recording'         => ['editing', 'needs_revision'],
    'editing'           => ['clipping', 'needs_revision'],
    'clipping'          => ['optimized', 'needs_revision'],
    'optimized'         => ['awaiting_approval', 'needs_revision'],
    'awaiting_approval' => ['approved', 'needs_revision'],  // human_only
    'approved'          => ['scheduled', 'needs_revision'],
    'scheduled'         => ['published', 'approved'],
    'published'         => ['repost_ready', 'archived'],
    'repost_ready'      => ['scheduled', 'archived'],
    'needs_revision'    => ['researching', 'scripting', 'recording', 'editing', 'clipping', 'archived'],
    'failed'            => ['needs_revision', 'archived'],
    'archived'          => [],
];

// Transitions that require actor_type = 'human' (AI/system cannot do these)
const PTMD_LIFECYCLE_HUMAN_ONLY = ['awaiting_approval' => ['approved']];

/**
 * Check if a transition is allowed.
 *
 * @param string $fromState  Current state
 * @param string $toState    Target state
 * @param string $actorType  'human', 'ai', 'system', 'cron', 'api'
 */
function ptmd_lifecycle_can_transition(
    string $fromState,
    string $toState,
    string $actorType = 'human'
): bool {
    $allowed = PTMD_LIFECYCLE_TRANSITION_MAP[$fromState] ?? [];
    if (!in_array($toState, $allowed, true)) {
        return false;
    }

    // Check human-only gate
    $humanOnlyTargets = PTMD_LIFECYCLE_HUMAN_ONLY[$fromState] ?? [];
    if (in_array($toState, $humanOnlyTargets, true) && $actorType !== 'human') {
        return false;
    }

    return true;
}

/**
 * Get current state of an entity from content_state_transitions (latest row).
 * Returns null if no transition has been recorded yet.
 *
 * @param string $entityType e.g. 'case', 'clip', 'asset'
 * @param int    $entityId
 */
function ptmd_lifecycle_current_state(string $entityType, int $entityId): ?string
{
    $pdo = get_db();
    if (!$pdo) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT to_state FROM content_state_transitions
              WHERE entity_type = :entity_type AND entity_id = :entity_id
              ORDER BY transitioned_at DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([':entity_type' => $entityType, ':entity_id' => $entityId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? (string) $row['to_state'] : null;
    } catch (\Throwable $e) {
        error_log('[PTMD Lifecycle] ptmd_lifecycle_current_state failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Record a state transition and emit a tracking event.
 *
 * @param string      $entityType 'case', 'clip', 'asset', 'idea'
 * @param int         $entityId
 * @param string      $toState    Target state
 * @param string      $actorType  'human', 'ai', 'system', 'cron', 'api'
 * @param int|null    $actorId    User ID if actor is human
 * @param string      $reason     Optional explanation
 * @param array       $meta       Extra metadata
 * @param string|null $traceId    Correlation trace ID
 * @return array ['ok'=>bool, 'error'=>string|null, 'transition_id'=>int|null]
 */
function ptmd_lifecycle_transition(
    string $entityType,
    int $entityId,
    string $toState,
    string $actorType = 'human',
    ?int $actorId = null,
    string $reason = '',
    array $meta = [],
    ?string $traceId = null
): array {
    $fromState = ptmd_lifecycle_current_state($entityType, $entityId);

    if ($fromState === null) {
        // Allow bootstrapping an entity into its initial state
        if (!in_array($toState, PTMD_LIFECYCLE_STATES, true)) {
            return ['ok' => false, 'error' => "Unknown state: {$toState}", 'transition_id' => null];
        }
    } else {
        if (!ptmd_lifecycle_can_transition($fromState, $toState, $actorType)) {
            $msg = "Transition from '{$fromState}' to '{$toState}' not allowed for actor_type '{$actorType}'";
            return ['ok' => false, 'error' => $msg, 'transition_id' => null];
        }
    }

    $pdo = get_db();
    if (!$pdo) {
        return ['ok' => false, 'error' => 'No database connection', 'transition_id' => null];
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO content_state_transitions
                (entity_type, entity_id, from_state, to_state,
                 actor_type, actor_id, reason, meta, transitioned_at)
             VALUES
                (:entity_type, :entity_id, :from_state, :to_state,
                 :actor_type, :actor_id, :reason, :meta, NOW())'
        );
        $stmt->execute([
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':from_state'  => $fromState,
            ':to_state'    => $toState,
            ':actor_type'  => $actorType,
            ':actor_id'    => $actorId,
            ':reason'      => $reason,
            ':meta'        => !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);

        $transitionId = (int) $pdo->lastInsertId();

        ptmd_emit_event(
            'content.state.changed',
            'lifecycle',
            $entityType,
            $entityId,
            array_merge($meta, ['from' => $fromState, 'to' => $toState, 'reason' => $reason]),
            $actorId,
            $traceId,
            null,
            $fromState,
            $toState,
            null,
            null,
            $actorType
        );

        return ['ok' => true, 'error' => null, 'transition_id' => $transitionId];
    } catch (\Throwable $e) {
        error_log('[PTMD Lifecycle] ptmd_lifecycle_transition failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage(), 'transition_id' => null];
    }
}

/**
 * Get full transition history for an entity.
 *
 * @param string $entityType
 * @param int    $entityId
 * @param int    $limit
 * @return array
 */
function ptmd_lifecycle_history(string $entityType, int $entityId, int $limit = 50): array
{
    $pdo = get_db();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM content_state_transitions
              WHERE entity_type = :entity_type AND entity_id = :entity_id
              ORDER BY transitioned_at DESC, id DESC
              LIMIT :lim'
        );
        $stmt->bindValue(':entity_type', $entityType);
        $stmt->bindValue(':entity_id', $entityId, \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('[PTMD Lifecycle] ptmd_lifecycle_history failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get all entities currently in a given state.
 *
 * @param string $entityType
 * @param string $state
 * @param int    $limit
 * @return array
 */
function ptmd_lifecycle_entities_in_state(string $entityType, string $state, int $limit = 100): array
{
    $pdo = get_db();
    if (!$pdo) {
        return [];
    }

    try {
        // Subquery: get the latest transition per entity, then filter by to_state
        $stmt = $pdo->prepare(
            'SELECT t.*
               FROM content_state_transitions t
               INNER JOIN (
                   SELECT entity_id, MAX(id) AS max_id
                     FROM content_state_transitions
                    WHERE entity_type = :entity_type
                    GROUP BY entity_id
               ) latest ON t.id = latest.max_id
              WHERE t.to_state = :state
              ORDER BY t.transitioned_at DESC
              LIMIT :lim'
        );
        $stmt->bindValue(':entity_type', $entityType);
        $stmt->bindValue(':state', $state);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('[PTMD Lifecycle] ptmd_lifecycle_entities_in_state failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get stale entities (in a non-terminal state for more than $staleDays days).
 *
 * @param string $entityType
 * @param int    $staleDays
 * @return array
 */
function ptmd_lifecycle_stale_entities(string $entityType, int $staleDays = 7): array
{
    $terminalStates = ['published', 'archived', 'failed'];
    $pdo = get_db();
    if (!$pdo) {
        return [];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($terminalStates), '?'));
        $stmt = $pdo->prepare(
            "SELECT t.*
               FROM content_state_transitions t
               INNER JOIN (
                   SELECT entity_id, MAX(id) AS max_id
                     FROM content_state_transitions
                    WHERE entity_type = ?
                    GROUP BY entity_id
               ) latest ON t.id = latest.max_id
              WHERE t.to_state NOT IN ({$placeholders})
                AND t.transitioned_at < NOW() - INTERVAL ? DAY
              ORDER BY t.transitioned_at ASC"
        );

        $params = array_merge([$entityType], $terminalStates, [$staleDays]);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('[PTMD Lifecycle] ptmd_lifecycle_stale_entities failed: ' . $e->getMessage());
        return [];
    }
}

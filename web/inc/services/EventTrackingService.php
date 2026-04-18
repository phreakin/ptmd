<?php

declare(strict_types=1);

/**
 * PTMD — Event Tracking Service
 * Emits structured events for observability across every other service.
 */

/**
 * Emit a structured PTMD event to ptmd_events.
 * Called by every other service for observability.
 *
 * @param string      $eventName   e.g. 'hook.generated', 'case.state_changed'
 * @param string      $module      e.g. 'hooks', 'lifecycle', 'optimizer', 'copilot'
 * @param string|null $objectType  e.g. 'hook', 'case', 'clip'
 * @param int|null    $objectId
 * @param array       $meta        Freeform key/value metadata
 * @param int|null    $userId
 * @param string|null $traceId     Correlation ID for cross-service tracing
 * @param string|null $sessionId
 * @param string|null $beforeState State before transition
 * @param string|null $afterState  State after transition
 * @param float|null  $confidence  For AI-related events
 * @param string|null $status
 * @param string|null $source      'human', 'ai', 'system', 'cron', 'api'
 */
function ptmd_emit_event(
    string $eventName,
    string $module,
    ?string $objectType = null,
    ?int $objectId = null,
    array $meta = [],
    ?int $userId = null,
    ?string $traceId = null,
    ?string $sessionId = null,
    ?string $beforeState = null,
    ?string $afterState = null,
    ?float $confidence = null,
    ?string $status = null,
    ?string $source = null
): bool {
    $prefix = explode('.', $eventName)[0] ?? '';

    if (in_array($prefix, ['ai', 'copilot'], true)) {
        $category = 'ai';
    } elseif ($prefix === 'hook') {
        $category = 'content';
    } elseif (in_array($prefix, ['case', 'clip', 'asset', 'idea', 'trend', 'content'], true)) {
        $category = 'content';
    } elseif (in_array($prefix, ['queue', 'post'], true)) {
        $category = 'posting';
    } elseif ($prefix === 'experiment') {
        $category = 'analytics';
    } elseif (in_array($prefix, ['system', 'job', 'webhook'], true)) {
        $category = 'system';
    } else {
        $category = 'system';
    }

    $pdo = get_db();
    if (!$pdo) {
        error_log('[PTMD EventTracking] ptmd_emit_event: no DB connection');
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO ptmd_events
                (event_name, event_category, module, object_type, object_id,
                 meta, user_id, trace_id, session_id,
                 before_state, after_state, confidence, status, source, created_at)
             VALUES
                (:event_name, :event_category, :module, :object_type, :object_id,
                 :meta, :user_id, :trace_id, :session_id,
                 :before_state, :after_state, :confidence, :status, :source, NOW())'
        );

        $stmt->execute([
            ':event_name'     => $eventName,
            ':event_category' => $category,
            ':module'         => $module,
            ':object_type'    => $objectType,
            ':object_id'      => $objectId,
            ':meta'           => !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            ':user_id'        => $userId,
            ':trace_id'       => $traceId,
            ':session_id'     => $sessionId,
            ':before_state'   => $beforeState,
            ':after_state'    => $afterState,
            ':confidence'     => $confidence,
            ':status'         => $status,
            ':source'         => $source,
        ]);

        return true;
    } catch (\Throwable $e) {
        error_log('[PTMD EventTracking] ptmd_emit_event failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Generate a random trace ID for correlating related events.
 */
function ptmd_generate_trace_id(): string
{
    return bin2hex(random_bytes(8));
}

/**
 * Record an AI usage cost entry.
 *
 * @param string   $feature       Feature name (e.g. 'copilot', 'title_generation')
 * @param string   $model         Model identifier (e.g. 'gpt-4o-mini')
 * @param int      $promptTokens
 * @param int      $responseTokens
 * @param int|null $sessionId     ai_assistant_sessions.id if applicable
 * @param int|null $generationId  ai_generations.id if applicable
 */
function ptmd_record_ai_cost(
    string $feature,
    string $model,
    int $promptTokens,
    int $responseTokens,
    ?int $sessionId = null,
    ?int $generationId = null
): void {
    $promptRate     = (float) site_setting('ai_cost_prompt_per_1k', '0.00015');
    $completionRate = (float) site_setting('ai_cost_completion_per_1k', '0.0006');

    $promptCost     = ($promptTokens / 1000) * $promptRate;
    $completionCost = ($responseTokens / 1000) * $completionRate;
    $totalCost      = $promptCost + $completionCost;

    $pdo = get_db();
    if (!$pdo) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO ai_usage_costs
                (feature, model, prompt_tokens, response_tokens,
                 prompt_cost, completion_cost, total_cost,
                 session_id, generation_id, created_at)
             VALUES
                (:feature, :model, :prompt_tokens, :response_tokens,
                 :prompt_cost, :completion_cost, :total_cost,
                 :session_id, :generation_id, NOW())'
        );

        $stmt->execute([
            ':feature'         => $feature,
            ':model'           => $model,
            ':prompt_tokens'   => $promptTokens,
            ':response_tokens' => $responseTokens,
            ':prompt_cost'     => round($promptCost, 8),
            ':completion_cost' => round($completionCost, 8),
            ':total_cost'      => round($totalCost, 8),
            ':session_id'      => $sessionId,
            ':generation_id'   => $generationId,
        ]);
    } catch (\Throwable $e) {
        error_log('[PTMD EventTracking] ptmd_record_ai_cost failed: ' . $e->getMessage());
    }
}

<?php

declare(strict_types=1);

/**
 * PTMD — Copilot Orchestrator Service
 * Context-aware, mode-aware AI assistant orchestration layer.
 */

require_once __DIR__ . '/EventTrackingService.php';
require_once __DIR__ . '/HookService.php';
require_once __DIR__ . '/ContentGenerationService.php';
require_once __DIR__ . '/TrendIntakeService.php';

/**
 * Process a user message within a copilot session.
 * Loads context based on scope, builds prompt, calls AI, saves explanation and actions.
 *
 * @param int         $sessionId     ai_assistant_sessions.id
 * @param string      $message       User message
 * @param string      $scope         'all'|'case'|'clips'|'queue'|'analytics'|'trends'|'assets'|'hooks'
 * @param int|null    $contextObjId  ID of the specific object in scope (e.g. case_id)
 * @param int         $userId        Current admin user ID
 * @param string      $mode          ask|explain|optimize|investigate|recommend|operations|reporting|navigation
 * @return array ['ok'=>bool, 'message_id'=>int, 'reply'=>string, 'refs'=>[], 'actions'=>[],
 *               'explanation'=>[], 'error'=>string|null]
 */
function ptmd_copilot_chat(
    int $sessionId,
    string $message,
    string $scope = 'all',
    ?int $contextObjId = null,
    int $userId = 0,
    string $mode = 'ask'
): array {
    $pdo = get_db();
    if (!$pdo) {
        return ['ok' => false, 'message_id' => 0, 'reply' => '', 'refs' => [],
                'actions' => [], 'explanation' => [], 'error' => 'No database connection'];
    }

    try {
        // Validate session
        $sStmt = $pdo->prepare('SELECT * FROM ai_assistant_sessions WHERE id = :id LIMIT 1');
        $sStmt->execute([':id' => $sessionId]);
        $session = $sStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$session) {
            return ['ok' => false, 'message_id' => 0, 'reply' => '', 'refs' => [],
                    'actions' => [], 'explanation' => [], 'error' => "Session #{$sessionId} not found"];
        }

        // Load last 10 messages for context
        $mStmt = $pdo->prepare(
            'SELECT role, content FROM ai_assistant_messages
              WHERE session_id = :session_id
              ORDER BY id DESC
              LIMIT 10'
        );
        $mStmt->execute([':session_id' => $sessionId]);
        $historyRows = array_reverse($mStmt->fetchAll(\PDO::FETCH_ASSOC));
        $messages    = array_map(fn($r) => ['role' => $r['role'], 'content' => $r['content']], $historyRows);

        // Auto-detect mode from message keywords if mode = 'ask'
        if ($mode === 'ask') {
            $lower = strtolower($message);
            if (preg_match('/\b(explain|why|reason|because|how did)\b/', $lower)) {
                $mode = 'explain';
            } elseif (preg_match('/\b(optimize|improve|better|enhance|score higher)\b/', $lower)) {
                $mode = 'optimize';
            } elseif (preg_match('/\b(find|where|locate|show me|search)\b/', $lower)) {
                $mode = 'navigation';
            } elseif (preg_match('/\b(investigate|trace|debug|problem|why is it|failing)\b/', $lower)) {
                $mode = 'investigate';
            } elseif (preg_match('/\b(recommend|suggest|what should|next step|advise)\b/', $lower)) {
                $mode = 'recommend';
            } elseif (preg_match('/\b(queue|post|publish|schedule|dispatch)\b/', $lower)) {
                $mode = 'operations';
            } elseif (preg_match('/\b(report|summary|analytics|performance|stats|metrics)\b/', $lower)) {
                $mode = 'reporting';
            }
        }

        $contextStr  = ptmd_copilot_build_context($scope, $contextObjId);
        $systemPrompt = ptmd_copilot_build_system_prompt($mode, $contextStr);

        // Append current user message
        $messages[] = ['role' => 'user', 'content' => $message];

        $aiResult = openai_chat_multiturn($systemPrompt, $messages, 1200);

        if (empty($aiResult['content'])) {
            return ['ok' => false, 'message_id' => 0, 'reply' => '', 'refs' => [],
                    'actions' => [], 'explanation' => [], 'error' => 'AI returned no content'];
        }

        $reply          = (string) $aiResult['content'];
        $model          = $aiResult['model'] ?? 'gpt-4o-mini';
        $promptTokens   = (int) ($aiResult['prompt_tokens']   ?? 0);
        $responseTokens = (int) ($aiResult['response_tokens'] ?? 0);

        // Save user message
        $umStmt = $pdo->prepare(
            'INSERT INTO ai_assistant_messages (session_id, role, content, created_at)
             VALUES (:session_id, :role, :content, NOW())'
        );
        $umStmt->execute([':session_id' => $sessionId, ':role' => 'user', ':content' => $message]);

        // Save assistant reply
        $amStmt = $pdo->prepare(
            'INSERT INTO ai_assistant_messages (session_id, role, content, created_at)
             VALUES (:session_id, :role, :content, NOW())'
        );
        $amStmt->execute([':session_id' => $sessionId, ':role' => 'assistant', ':content' => $reply]);
        $messageId = (int) $pdo->lastInsertId();

        // Extract and save context refs
        $refs = ptmd_copilot_extract_refs($reply, $scope, $contextObjId);
        foreach ($refs as $ref) {
            $rStmt = $pdo->prepare(
                'INSERT INTO ai_assistant_context_refs
                    (session_id, message_id, ref_table, ref_id, ref_label, created_at)
                 VALUES (:session_id, :message_id, :ref_table, :ref_id, :ref_label, NOW())'
            );
            $rStmt->execute([
                ':session_id' => $sessionId,
                ':message_id' => $messageId,
                ':ref_table'  => $ref['ref_table'],
                ':ref_id'     => $ref['ref_id'],
                ':ref_label'  => $ref['ref_label'],
            ]);
        }

        // Extract and save suggested actions
        $rawActions  = ptmd_copilot_extract_actions($reply, $refs, $userId);
        $savedActionIds = [];
        foreach ($rawActions as $actionData) {
            $actionId         = ptmd_copilot_save_action($sessionId, $messageId, $actionData);
            $savedActionIds[] = $actionId;
            $actionData['id'] = $actionId;
        }

        // Build and save explanation
        $explanation = ptmd_copilot_build_explanation($refs, []);
        if (!empty($explanation)) {
            $exStmt = $pdo->prepare(
                'INSERT INTO ai_assistant_explanations
                    (session_id, message_id, explanation_text, refs_json, created_at)
                 VALUES (:session_id, :message_id, :explanation_text, :refs_json, NOW())'
            );
            $exStmt->execute([
                ':session_id'       => $sessionId,
                ':message_id'       => $messageId,
                ':explanation_text' => $explanation['summary'] ?? '',
                ':refs_json'        => json_encode($refs, JSON_UNESCAPED_UNICODE),
            ]);
        }

        ptmd_record_ai_cost('copilot', $model, $promptTokens, $responseTokens, $sessionId);

        ptmd_emit_event(
            'copilot.message.created',
            'copilot',
            'ai_session',
            $sessionId,
            ['scope' => $scope, 'mode' => $mode, 'refs_count' => count($refs), 'actions_count' => count($rawActions)],
            $userId,
            ptmd_generate_trace_id(),
            (string) $sessionId,
            null, null,
            null,
            'created',
            'ai'
        );

        return [
            'ok'          => true,
            'message_id'  => $messageId,
            'reply'       => $reply,
            'refs'        => $refs,
            'actions'     => $rawActions,
            'explanation' => $explanation,
            'error'       => null,
        ];
    } catch (\Throwable $e) {
        error_log('[PTMD Copilot] ptmd_copilot_chat failed: ' . $e->getMessage());
        return ['ok' => false, 'message_id' => 0, 'reply' => '', 'refs' => [],
                'actions' => [], 'explanation' => [], 'error' => $e->getMessage()];
    }
}

/**
 * Build a structured context string for the AI based on scope.
 * Injected into the system prompt so the AI has live data.
 *
 * @param string   $scope
 * @param int|null $contextObjId
 * @return string
 */
function ptmd_copilot_build_context(string $scope, ?int $contextObjId = null): string
{
    $pdo = get_db();
    if (!$pdo) {
        return '[No database connection — context unavailable]';
    }

    $lines = [];

    try {
        switch ($scope) {
            case 'case':
                if ($contextObjId) {
                    $cStmt = $pdo->prepare('SELECT * FROM cases WHERE id = :id LIMIT 1');
                    $cStmt->execute([':id' => $contextObjId]);
                    $case = $cStmt->fetch(\PDO::FETCH_ASSOC);
                    if ($case) {
                        $lines[] = "CASE #{$case['id']}: {$case['title']} | Status: {$case['status']}";
                        $lines[] = 'Description: ' . ($case['description'] ?? $case['summary'] ?? 'N/A');
                    }

                    $clipStmt = $pdo->prepare(
                        'SELECT id, label, status FROM video_clips WHERE case_id = :case_id ORDER BY id DESC LIMIT 10'
                    );
                    $clipStmt->execute([':case_id' => $contextObjId]);
                    $clips = $clipStmt->fetchAll(\PDO::FETCH_ASSOC);
                    $lines[] = 'Clips (' . count($clips) . '): ' . implode(', ', array_map(fn($c) => "#{$c['id']} {$c['label']} [{$c['status']}]", $clips));

                    $hookStmt = $pdo->prepare(
                        'SELECT id, hook_type, hook_text, status FROM hooks WHERE case_id = :case_id ORDER BY confidence_score DESC LIMIT 5'
                    );
                    $hookStmt->execute([':case_id' => $contextObjId]);
                    $hooks = $hookStmt->fetchAll(\PDO::FETCH_ASSOC);
                    $lines[] = 'Recent Hooks (' . count($hooks) . '): ' . implode('; ', array_map(fn($h) => "#{$h['id']} [{$h['hook_type']}] {$h['status']}", $hooks));
                }
                break;

            case 'clips':
                $stmt = $pdo->prepare(
                    'SELECT id, label, status FROM video_clips ORDER BY id DESC LIMIT 20'
                );
                $stmt->execute();
                $clips   = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $lines[] = 'Recent Clips: ' . implode(', ', array_map(fn($c) => "#{$c['id']} {$c['label']} [{$c['status']}]", $clips));
                break;

            case 'queue':
                $stmt = $pdo->prepare(
                    "SELECT platform, status, COUNT(*) AS cnt FROM social_post_queue GROUP BY platform, status"
                );
                $stmt->execute();
                $rows    = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $lines[] = 'Queue summary: ' . implode('; ', array_map(fn($r) => "{$r['platform']} {$r['status']}: {$r['cnt']}", $rows));
                break;

            case 'analytics':
                $stmt = $pdo->prepare(
                    'SELECT metric_date, total_views, total_plays FROM kpi_daily_rollups ORDER BY metric_date DESC LIMIT 7'
                );
                $stmt->execute();
                $rows    = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $lines[] = 'Last 7-day KPIs: ' . implode('; ', array_map(fn($r) => "{$r['metric_date']}: views={$r['total_views']} plays={$r['total_plays']}", $rows));
                break;

            case 'trends':
                $clusters = ptmd_trend_get_active_clusters(10);
                $lines[]  = 'Active Trend Clusters (' . count($clusters) . '): '
                    . implode('; ', array_map(fn($c) => "{$c['label']} (score: {$c['trend_score']})", $clusters));
                break;

            case 'assets':
                $stmt = $pdo->prepare('SELECT COUNT(*) AS total FROM media_library');
                $stmt->execute();
                $total   = $stmt->fetchColumn();
                $lines[] = "Media Library: {$total} total assets";
                break;

            case 'hooks':
                $summary = ptmd_hook_lab_summary(null, null, 30);
                $lines[] = 'Hook Lab (30d): ' . implode('; ', array_map(
                    fn($r) => "{$r['hook_type']} [{$r['platform']}]: {$r['total_hooks']} hooks, win_rate={$r['win_rate']}%",
                    array_slice($summary, 0, 5)
                ));
                break;

            case 'all':
            default:
                $cStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM cases");
                $cStmt->execute();
                $lines[] = 'Cases: ' . $cStmt->fetchColumn();

                $qStmt = $pdo->prepare("SELECT status, COUNT(*) AS c FROM social_post_queue GROUP BY status");
                $qStmt->execute();
                $qRows   = $qStmt->fetchAll(\PDO::FETCH_ASSOC);
                $lines[] = 'Queue: ' . implode(', ', array_map(fn($r) => "{$r['status']}: {$r['c']}", $qRows));

                $gStmt = $pdo->prepare("SELECT COUNT(*) FROM ai_generations WHERE created_at >= NOW() - INTERVAL 7 DAY");
                $gStmt->execute();
                $lines[] = 'AI generations (7d): ' . $gStmt->fetchColumn();

                $clusters = ptmd_trend_get_active_clusters(3);
                $lines[]  = 'Top trends: ' . implode(', ', array_column($clusters, 'label'));
                break;
        }
    } catch (\Throwable $e) {
        error_log('[PTMD Copilot] ptmd_copilot_build_context failed: ' . $e->getMessage());
        $lines[] = '[Context load error: ' . $e->getMessage() . ']';
    }

    return implode("\n", $lines);
}

/**
 * Build the full system prompt for the copilot including mode instructions.
 *
 * @param string $mode
 * @param string $contextStr
 * @return string
 */
function ptmd_copilot_build_system_prompt(string $mode, string $contextStr): string
{
    $base = ptmd_copilot_system_prompt();

    $modeInstructions = match ($mode) {
        'explain'     => "MODE: EXPLAIN\nYour job is to explain decisions, states, or AI recommendations clearly. Cite specific record IDs. Explain WHY, not just what. Include confidence levels.",
        'optimize'    => "MODE: OPTIMIZE\nSuggest concrete improvements. Provide specific alternative text, scores, or strategies. Always include confidence level. Format actionable suggestions as JSON action blocks.",
        'investigate' => "MODE: INVESTIGATE\nTrace issues systematically through the data. Reference specific records. Identify root causes. Suggest remediation steps.",
        'recommend'   => "MODE: RECOMMEND\nSuggest the single best next action. Include a confidence level and human_review_recommended flag. Use JSON action blocks for executable suggestions.",
        'operations'  => "MODE: OPERATIONS\nFocus on queue health, scheduling, and publishing workflows. Identify blockers. Suggest queue operations using JSON action blocks.",
        'reporting'   => "MODE: REPORTING\nSummarise metrics, trends, and performance data. Highlight anomalies. Provide period-over-period comparisons where data allows.",
        'navigation'  => "MODE: NAVIGATION\nHelp locate records or workflows quickly. Reference specific IDs, statuses, and table names.",
        default       => "MODE: ASK\nAnswer questions about system state accurately. Cite specific record IDs when referencing data.",
    };

    return <<<PROMPT
{$base}

{$modeInstructions}

LIVE SYSTEM CONTEXT:
{$contextStr}

IMPORTANT RULES:
- Always cite record IDs (e.g. "case #12", "hook #45") when referencing data.
- Include confidence level (0-100%) in any recommendation.
- If a recommendation requires human approval, include: human_review_recommended: true
- For executable suggestions, include a JSON block in this format:
```json
{"action_type": "...", "target_table": "...", "target_id": 0, "payload": {}, "risk_level": "low|medium|high"}
```
PROMPT;
}

/**
 * Extract cited record references from AI reply text.
 *
 * @param string   $replyText
 * @param string   $scope
 * @param int|null $contextObjId
 * @return array Array of ['ref_table'=>string, 'ref_id'=>int, 'ref_label'=>string]
 */
function ptmd_copilot_extract_refs(string $replyText, string $scope, ?int $contextObjId): array
{
    $refs = [];
    $seen = [];

    // Patterns: "case #12", "hook #45", "clip #7", etc.
    $tableMap = [
        'case'          => 'cases',
        'clip'          => 'video_clips',
        'hook'          => 'hooks',
        'asset'         => 'assets',
        'queue item'    => 'social_post_queue',
        'generation'    => 'ai_generations',
        'cluster'       => 'trend_clusters',
        'signal'        => 'trend_signals',
        'session'       => 'ai_assistant_sessions',
    ];

    foreach ($tableMap as $label => $table) {
        $pattern = '/\b' . preg_quote($label, '/') . '\s+#(\d+)/i';
        if (preg_match_all($pattern, $replyText, $matches)) {
            foreach ($matches[1] as $id) {
                $key = $table . ':' . $id;
                if (!isset($seen[$key])) {
                    $refs[]     = ['ref_table' => $table, 'ref_id' => (int) $id, 'ref_label' => "{$label} #{$id}"];
                    $seen[$key] = true;
                }
            }
        }
    }

    // Include the scoped context object automatically if not already referenced
    if ($contextObjId && $scope === 'case') {
        $key = 'cases:' . $contextObjId;
        if (!isset($seen[$key])) {
            $refs[] = ['ref_table' => 'cases', 'ref_id' => $contextObjId, 'ref_label' => "case #{$contextObjId}"];
        }
    }

    return $refs;
}

/**
 * Extract suggested actions from AI reply.
 * Parses ```json blocks with action_type, target_table, target_id, payload, risk_level.
 *
 * @param string $replyText
 * @param array  $refs
 * @param int    $userId
 * @return array Array of action data arrays
 */
function ptmd_copilot_extract_actions(string $replyText, array $refs, int $userId): array
{
    $actions = [];

    if (!preg_match_all('/```(?:json)?\s*([\s\S]*?)```/i', $replyText, $blocks)) {
        return $actions;
    }

    foreach ($blocks[1] as $block) {
        $decoded = json_decode(trim($block), true);
        if (!is_array($decoded) || empty($decoded['action_type'])) {
            continue;
        }

        $actions[] = [
            'action_type'  => (string) $decoded['action_type'],
            'target_table' => (string) ($decoded['target_table'] ?? ''),
            'target_id'    => (int)    ($decoded['target_id']    ?? 0),
            'payload'      => $decoded['payload']    ?? [],
            'risk_level'   => (string) ($decoded['risk_level']   ?? 'medium'),
            'status'       => 'pending',
            'requested_by' => $userId,
        ];
    }

    return $actions;
}

/**
 * Save a copilot action to ai_assistant_actions.
 *
 * @param int      $sessionId
 * @param int|null $messageId
 * @param array    $actionData
 * @return int New action ID
 */
function ptmd_copilot_save_action(int $sessionId, ?int $messageId, array $actionData): int
{
    $pdo = get_db();
    if (!$pdo) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO ai_assistant_actions
                (session_id, message_id, action_type, target_table, target_id,
                 payload, risk_level, status, requested_by, created_at)
             VALUES
                (:session_id, :message_id, :action_type, :target_table, :target_id,
                 :payload, :risk_level, :status, :requested_by, NOW())'
        );
        $stmt->execute([
            ':session_id'  => $sessionId,
            ':message_id'  => $messageId,
            ':action_type' => $actionData['action_type']  ?? '',
            ':target_table'=> $actionData['target_table'] ?? '',
            ':target_id'   => $actionData['target_id']    ?? null,
            ':payload'     => json_encode($actionData['payload'] ?? [], JSON_UNESCAPED_UNICODE),
            ':risk_level'  => $actionData['risk_level']   ?? 'medium',
            ':status'      => 'pending',
            ':requested_by'=> $actionData['requested_by'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    } catch (\Throwable $e) {
        error_log('[PTMD Copilot] ptmd_copilot_save_action failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Approve and execute a copilot action.
 *
 * @param int $actionId
 * @param int $userId
 * @return array ['ok'=>bool, 'result'=>mixed, 'error'=>string|null]
 */
function ptmd_copilot_execute_action(int $actionId, int $userId): array
{
    $pdo = get_db();
    if (!$pdo) {
        return ['ok' => false, 'result' => null, 'error' => 'No database connection'];
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM ai_assistant_actions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $actionId]);
        $action = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$action) {
            return ['ok' => false, 'result' => null, 'error' => "Action #{$actionId} not found"];
        }

        $payload    = is_string($action['payload']) ? json_decode($action['payload'], true) : ($action['payload'] ?? []);
        $targetId   = (int) ($action['target_id'] ?? 0);
        $actionType = (string) $action['action_type'];

        $result = match ($actionType) {
            'generate_titles'   => ptmd_generate_titles($targetId, $payload),
            'generate_hooks'    => ptmd_hook_generate($targetId, 'all', null, $payload, $userId),
            'generate_captions' => ptmd_generate_captions($targetId, null),
            'flag_issue'        => (function () use ($pdo, $targetId, $action, $userId, $payload): array {
                $ins = $pdo->prepare(
                    'INSERT INTO editorial_approvals
                        (entity_type, entity_id, request_type, requested_by, notes, created_at)
                     VALUES (:entity_type, :entity_id, :request_type, :requested_by, :notes, NOW())'
                );
                $ins->execute([
                    ':entity_type'   => $action['target_table'],
                    ':entity_id'     => $targetId,
                    ':request_type'  => 'review',
                    ':requested_by'  => $userId,
                    ':notes'         => $payload['notes'] ?? '',
                ]);
                return ['ok' => true, 'approval_id' => (int) $pdo->lastInsertId()];
            })(),
            'mark_review'       => (function () use ($pdo, $targetId, $action, $userId): array {
                $upd = $pdo->prepare(
                    "UPDATE editorial_approvals
                        SET status = 'approved', reviewed_by = :reviewed_by, reviewed_at = NOW()
                      WHERE entity_type = :entity_type AND entity_id = :entity_id"
                );
                $upd->execute([
                    ':reviewed_by'  => $userId,
                    ':entity_type'  => $action['target_table'],
                    ':entity_id'    => $targetId,
                ]);
                return ['ok' => true];
            })(),
            'create_note'       => (function () use ($pdo, $targetId, $action, $userId, $payload): array {
                $ins = $pdo->prepare(
                    'INSERT INTO override_actions
                        (entity_type, entity_id, override_type, override_value, reason, overridden_by, created_at)
                     VALUES (:entity_type, :entity_id, :override_type, :override_value, :reason, :overridden_by, NOW())'
                );
                $ins->execute([
                    ':entity_type'   => $action['target_table'],
                    ':entity_id'     => $targetId,
                    ':override_type' => 'editorial_note',
                    ':override_value'=> $payload['note'] ?? '',
                    ':reason'        => $payload['reason'] ?? '',
                    ':overridden_by' => $userId,
                ]);
                return ['ok' => true, 'override_id' => (int) $pdo->lastInsertId()];
            })(),
            'queue_post'        => (function () use ($targetId, $userId, $payload): array {
                require_once __DIR__ . '/LifecycleService.php';
                return ptmd_lifecycle_transition($payload['entity_type'] ?? 'case', $targetId, 'scheduled', 'human', $userId, 'Copilot action');
            })(),
            default             => ['ok' => false, 'error' => 'Action type not executable'],
        };

        // Update action status
        $upd = $pdo->prepare(
            "UPDATE ai_assistant_actions
                SET status = 'executed', executed_by = :executed_by, executed_at = NOW()
              WHERE id = :id"
        );
        $upd->execute([':executed_by' => $userId, ':id' => $actionId]);

        // Log execution
        $logStmt = $pdo->prepare(
            'INSERT INTO ai_assistant_action_logs
                (action_id, result_json, executed_by, executed_at)
             VALUES (:action_id, :result_json, :executed_by, NOW())'
        );
        $logStmt->execute([
            ':action_id'   => $actionId,
            ':result_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
            ':executed_by' => $userId,
        ]);

        ptmd_emit_event(
            'copilot.action.executed',
            'copilot',
            'ai_assistant_action',
            $actionId,
            ['action_type' => $actionType, 'target_id' => $targetId, 'ok' => $result['ok'] ?? false],
            $userId,
            null, null, null, null, null,
            'executed',
            'human'
        );

        return ['ok' => true, 'result' => $result, 'error' => null];
    } catch (\Throwable $e) {
        error_log('[PTMD Copilot] ptmd_copilot_execute_action failed: ' . $e->getMessage());
        return ['ok' => false, 'result' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Build an explanation record from context refs and factors.
 *
 * @param array $refs             Array of ref objects
 * @param array $factorsFromContext
 * @return array ['summary'=>string, 'refs_count'=>int]
 */
function ptmd_copilot_build_explanation(array $refs, array $factorsFromContext): array
{
    if (empty($refs)) {
        return ['summary' => 'No specific records cited in this response.', 'refs_count' => 0];
    }

    $tables  = array_unique(array_column($refs, 'ref_table'));
    $summary = 'This response references ' . count($refs) . ' record(s) across: ' . implode(', ', $tables) . '.';

    return ['summary' => $summary, 'refs_count' => count($refs)];
}

/**
 * Get session with message history and context refs.
 *
 * @param int $sessionId
 * @return array|null
 */
function ptmd_copilot_get_session(int $sessionId): ?array
{
    $pdo = get_db();
    if (!$pdo) {
        return null;
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM ai_assistant_sessions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $sessionId]);
        $session = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$session) {
            return null;
        }

        $mStmt = $pdo->prepare(
            'SELECT * FROM ai_assistant_messages WHERE session_id = :session_id ORDER BY id ASC'
        );
        $mStmt->execute([':session_id' => $sessionId]);
        $session['messages'] = $mStmt->fetchAll(\PDO::FETCH_ASSOC);

        $rStmt = $pdo->prepare(
            'SELECT * FROM ai_assistant_context_refs WHERE session_id = :session_id ORDER BY id DESC LIMIT 50'
        );
        $rStmt->execute([':session_id' => $sessionId]);
        $session['refs'] = $rStmt->fetchAll(\PDO::FETCH_ASSOC);

        return $session;
    } catch (\Throwable $e) {
        error_log('[PTMD Copilot] ptmd_copilot_get_session failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Pin an AI message as an internal editorial note.
 *
 * @param int $messageId
 * @param int $userId
 * @return bool
 */
function ptmd_copilot_pin_message(int $messageId, int $userId): bool
{
    $pdo = get_db();
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE ai_assistant_messages
                SET is_pinned = 1, pinned_by = :pinned_by, pinned_at = NOW()
              WHERE id = :id'
        );
        $stmt->execute([':pinned_by' => $userId, ':id' => $messageId]);

        ptmd_emit_event('copilot.message.pinned', 'copilot', 'ai_assistant_message', $messageId,
            [], $userId, null, null, null, null, null, 'pinned', 'human');

        return true;
    } catch (\Throwable $e) {
        error_log('[PTMD Copilot] ptmd_copilot_pin_message failed: ' . $e->getMessage());
        return false;
    }
}

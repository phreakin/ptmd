<?php
/**
 * PTMD / Social Ledger agent task helpers
 *
 * Purpose:
 * - Normalize task payloads for all agents
 * - Support template-driven execution
 * - Provide A2A-shaped internal task structure
 * - Keep task creation, validation, and lifecycle consistent
 */

declare(strict_types=1);

if (!function_exists('agent_task_statuses')) {
    function agent_task_statuses(): array
    {
        return [
            'submitted',
            'working',
            'input_needed',
            'completed',
            'failed',
            'canceled',
        ];
    }
}

if (!function_exists('agent_task_status_is_valid')) {
    function agent_task_status_is_valid(string $status): bool
    {
        return in_array($status, agent_task_statuses(), true);
    }
}

if (!function_exists('agent_task_id')) {
    function agent_task_id(): string
    {
        try {
            return 'agt_' . strtoupper(bin2hex(random_bytes(6)));
        } catch (Throwable $e) {
            return 'agt_' . strtoupper(substr(sha1(uniqid((string) mt_rand(), true)), 0, 12));
        }
    }
}

if (!function_exists('agent_task_message_id')) {
    function agent_task_message_id(): string
    {
        try {
            return 'msg_' . strtoupper(bin2hex(random_bytes(6)));
        } catch (Throwable $e) {
            return 'msg_' . strtoupper(substr(sha1(uniqid((string) mt_rand(), true)), 0, 12));
        }
    }
}

if (!function_exists('agent_task_correlation_id')) {
    function agent_task_correlation_id(): string
    {
        try {
            return 'cor_' . strtoupper(bin2hex(random_bytes(6)));
        } catch (Throwable $e) {
            return 'cor_' . strtoupper(substr(sha1(uniqid((string) mt_rand(), true)), 0, 12));
        }
    }
}

if (!function_exists('agent_task_now')) {
    function agent_task_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('agent_task_template_exists')) {
    function agent_task_template_exists(string $agentId, string $templateSlug): bool
    {
        $path = agent_task_template_path($agentId, $templateSlug);
        return $path !== null && is_file($path);
    }
}

if (!function_exists('agent_task_template_path')) {
    function agent_task_template_path(string $agentId, string $templateSlug): ?string
    {
        if (!function_exists('agent_templates_path')) {
            return null;
        }

        $basePath = agent_templates_path($agentId);
        if (!$basePath) {
            return null;
        }

        $safeSlug = preg_replace('/[^a-z0-9_\-]/i', '', $templateSlug);
        if ($safeSlug === '') {
            return null;
        }

        return rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeSlug . '.php';
    }
}

if (!function_exists('load_agent_template')) {
    function load_agent_template(string $agentId, string $templateSlug): ?array
    {
        $path = agent_task_template_path($agentId, $templateSlug);
        if (!$path || !is_file($path)) {
            return null;
        }

        $template = require $path;
        return is_array($template) ? $template : null;
    }
}

if (!function_exists('agent_task_base_payload')) {
    function agent_task_base_payload(
        string $agentId,
        string $taskType,
        array $inputPayload = [],
        array $options = []
    ): array {
        if (!function_exists('agent_exists') || !agent_exists($agentId)) {
            throw new InvalidArgumentException('Unknown agent: ' . $agentId);
        }

        $taskId        = $options['task_id']        ?? agent_task_id();
        $correlationId = $options['correlation_id'] ?? agent_task_correlation_id();
        $status        = $options['status']         ?? 'submitted';
        $now           = agent_task_now();

        if (!agent_task_status_is_valid($status)) {
            throw new InvalidArgumentException('Invalid agent task status: ' . $status);
        }

        return [
            'task_id'             => $taskId,
            'target_agent_id'     => $agentId,
            'source_actor'        => $options['source_actor'] ?? 'system',
            'source_agent_id'     => $options['source_agent_id'] ?? null,
            'task_type'           => $taskType,
            'template_slug'       => $options['template_slug'] ?? null,
            'status'              => $status,
            'input_payload_json'  => $inputPayload,
            'result_payload_json' => $options['result_payload_json'] ?? null,
            'error_payload_json'  => $options['error_payload_json'] ?? null,
            'context_json'        => $options['context_json'] ?? [],
            'messages'            => $options['messages'] ?? [],
            'artifacts'           => $options['artifacts'] ?? [],
            'priority'            => $options['priority'] ?? 'normal',
            'correlation_id'      => $correlationId,
            'created_at'          => $options['created_at'] ?? $now,
            'updated_at'          => $options['updated_at'] ?? $now,
        ];
    }
}

if (!function_exists('agent_task_from_template')) {
    function agent_task_from_template(
        string $agentId,
        string $templateSlug,
        array $inputs = [],
        array $options = []
    ): array {
        $template = load_agent_template($agentId, $templateSlug);

        if (!$template) {
            throw new RuntimeException("Template not found for agent [{$agentId}] slug [{$templateSlug}]");
        }

        $defaultInputs = $template['inputs'] ?? [];
        $mergedInputs  = array_replace_recursive($defaultInputs, $inputs);

        $taskType = $template['template']['id'] ?? $templateSlug;

        $payload = agent_task_base_payload(
            $agentId,
            $taskType,
            $mergedInputs,
            array_merge($options, [
                'template_slug' => $templateSlug,
                'context_json'  => array_merge(
                    $options['context_json'] ?? [],
                    [
                        'template'   => $template['template'] ?? [],
                        'agent_hint' => $template['agentHint'] ?? '',
                        'source'     => $template['source'] ?? 'template',
                    ]
                ),
            ])
        );

        return $payload;
    }
}

if (!function_exists('agent_task_set_status')) {
    function agent_task_set_status(array $task, string $status, ?array $errorPayload = null, ?array $resultPayload = null): array
    {
        if (!agent_task_status_is_valid($status)) {
            throw new InvalidArgumentException('Invalid agent task status: ' . $status);
        }

        $task['status'] = $status;
        $task['updated_at'] = agent_task_now();

        if ($errorPayload !== null) {
            $task['error_payload_json'] = $errorPayload;
        }

        if ($resultPayload !== null) {
            $task['result_payload_json'] = $resultPayload;
        }

        return $task;
    }
}

if (!function_exists('agent_task_add_message')) {
    function agent_task_add_message(array $task, string $role, string $content, array $meta = []): array
    {
        $allowedRoles = ['user', 'assistant', 'system', 'agent'];
        if (!in_array($role, $allowedRoles, true)) {
            throw new InvalidArgumentException('Invalid agent task message role: ' . $role);
        }

        if (!isset($task['messages']) || !is_array($task['messages'])) {
            $task['messages'] = [];
        }

        $task['messages'][] = [
            'message_id'  => agent_task_message_id(),
            'role'        => $role,
            'content'     => $content,
            'meta'        => $meta,
            'created_at'  => agent_task_now(),
        ];

        $task['updated_at'] = agent_task_now();

        return $task;
    }
}

if (!function_exists('agent_task_add_artifact')) {
    function agent_task_add_artifact(array $task, array $artifact): array
    {
        if (!isset($task['artifacts']) || !is_array($task['artifacts'])) {
            $task['artifacts'] = [];
        }

        $task['artifacts'][] = array_merge([
            'type'       => 'data',
            'label'      => 'Artifact',
            'path'       => null,
            'url'        => null,
            'data'       => null,
            'created_at' => agent_task_now(),
        ], $artifact);

        $task['updated_at'] = agent_task_now();

        return $task;
    }
}

if (!function_exists('agent_task_normalize')) {
    function agent_task_normalize(array $task): array
    {
        $task['task_id']             = $task['task_id']             ?? agent_task_id();
        $task['target_agent_id']     = $task['target_agent_id']     ?? '';
        $task['source_actor']        = $task['source_actor']        ?? 'system';
        $task['source_agent_id']     = $task['source_agent_id']     ?? null;
        $task['task_type']           = $task['task_type']           ?? 'unknown_task';
        $task['template_slug']       = $task['template_slug']       ?? null;
        $task['status']              = $task['status']              ?? 'submitted';
        $task['input_payload_json']  = $task['input_payload_json']  ?? [];
        $task['result_payload_json'] = $task['result_payload_json'] ?? null;
        $task['error_payload_json']  = $task['error_payload_json']  ?? null;
        $task['context_json']        = $task['context_json']        ?? [];
        $task['messages']            = $task['messages']            ?? [];
        $task['artifacts']           = $task['artifacts']           ?? [];
        $task['priority']            = $task['priority']            ?? 'normal';
        $task['correlation_id']      = $task['correlation_id']      ?? agent_task_correlation_id();
        $task['created_at']          = $task['created_at']          ?? agent_task_now();
        $task['updated_at']          = $task['updated_at']          ?? agent_task_now();

        if (!agent_task_status_is_valid((string) $task['status'])) {
            $task['status'] = 'submitted';
        }

        return $task;
    }
}

if (!function_exists('agent_task_to_json')) {
    function agent_task_to_json(array $task): string
    {
        return json_encode(agent_task_normalize($task), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}   
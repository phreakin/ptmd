<?php
/**
 * PTMD / Social Ledger agent registry
 *
 * Purpose:
 * - Define all known agents in one place
 * - Expose capabilities, labels, descriptions, and template groups
 * - Drive Agent Console / The Analyst task launcher
 * - Support future A2A-shaped internal architecture
 */

declare(strict_types=1);

if (!function_exists('agent_registry')) {
    function agent_registry(): array
    {
        return [
            'analyst' => [
                'agent_id'          => 'analyst',
                'name'              => 'The Analyst',
                'group'             => 'core',
                'description'       => 'Primary investigative and operational intelligence agent for cases, workflow, dispatch, assets, and decision support.',
                'icon'              => 'fa-solid fa-chart-line',
                'ui_color'          => 'teal',
                'enabled'           => true,
                'visible'           => true,
                'sort_order'        => 10,
                'templates_path'    => __DIR__ . '/templates/agents/analyst',
                'capabilities'      => [
                    'summarize_page',
                    'analyze_case',
                    'review_dispatch',
                    'identify_bottlenecks',
                    'prioritize_work',
                    'explain_system_state',
                ],
                'allowed_tools'     => [
                    'db_read',
                    'case_lookup',
                    'queue_lookup',
                    'asset_lookup',
                    'workflow_lookup',
                    'analytics_summary',
                ],
                'default_template'  => 'what_needs_attention',
                'task_statuses'     => [
                    'submitted',
                    'working',
                    'input_needed',
                    'completed',
                    'failed',
                    'canceled',
                ],
            ],

            'trend' => [
                'agent_id'          => 'trend',
                'name'              => 'Trend Agent',
                'group'             => 'research',
                'description'       => 'Finds emerging signals, fresh topics, trend shifts, and potential story angles.',
                'icon'              => 'fa-solid fa-waveform-lines',
                'ui_color'          => 'yellow',
                'enabled'           => true,
                'visible'           => true,
                'sort_order'        => 20,
                'templates_path'    => __DIR__ . '/templates/agents/trend',
                'capabilities'      => [
                    'topic_deep_dive',
                    'trend_scan',
                    'story_angle_finder',
                    'signal_summary',
                    'freshness_review',
                ],
                'allowed_tools'     => [
                    'db_read',
                    'trend_lookup',
                    'search_context',
                    'topic_history',
                ],
                'default_template'  => 'topic_deep_dive',
                'task_statuses'     => [
                    'submitted',
                    'working',
                    'input_needed',
                    'completed',
                    'failed',
                    'canceled',
                ],
            ],

            'posting' => [
                'agent_id'          => 'posting',
                'name'              => 'Posting Agent',
                'group'             => 'distribution',
                'description'       => 'Adapts content for platforms, validates posting constraints, and prepares queue-ready output.',
                'icon'              => 'fa-solid fa-paper-plane',
                'ui_color'          => 'blue',
                'enabled'           => true,
                'visible'           => true,
                'sort_order'        => 30,
                'templates_path'    => __DIR__ . '/templates/agents/posting',
                'capabilities'      => [
                    'platform_post_generator',
                    'caption_adapter',
                    'queue_post_prep',
                    'platform_validation',
                    'post_variant_generation',
                ],
                'allowed_tools'     => [
                    'db_read',
                    'platform_capabilities',
                    'queue_lookup',
                    'case_lookup',
                    'asset_lookup',
                ],
                'default_template'  => 'platform_post_generator',
                'task_statuses'     => [
                    'submitted',
                    'working',
                    'input_needed',
                    'completed',
                    'failed',
                    'canceled',
                ],
            ],

            'moderation' => [
                'agent_id'          => 'moderation',
                'name'              => 'Moderation Agent',
                'group'             => 'safety',
                'description'       => 'Reviews chat and moderation signals, identifies risk, and drafts moderation actions.',
                'icon'              => 'fa-solid fa-shield-halved',
                'ui_color'          => 'red',
                'enabled'           => true,
                'visible'           => true,
                'sort_order'        => 40,
                'templates_path'    => __DIR__ . '/templates/agents/moderation',
                'capabilities'      => [
                    'review_flagged_messages',
                    'moderation_risk_summary',
                    'repeat_offender_scan',
                    'incident_summary',
                ],
                'allowed_tools'     => [
                    'db_read',
                    'chat_lookup',
                    'moderation_lookup',
                    'user_lookup',
                ],
                'default_template'  => 'review_flagged_messages',
                'task_statuses'     => [
                    'submitted',
                    'working',
                    'input_needed',
                    'completed',
                    'failed',
                    'canceled',
                ],
            ],

            'analytics' => [
                'agent_id'          => 'analytics',
                'name'              => 'Analytics Agent',
                'group'             => 'intelligence',
                'description'       => 'Summarizes performance, compares outcomes, identifies weak points, and reports on content results.',
                'icon'              => 'fa-solid fa-chart-column',
                'ui_color'          => 'purple',
                'enabled'           => true,
                'visible'           => true,
                'sort_order'        => 50,
                'templates_path'    => __DIR__ . '/templates/agents/analytics',
                'capabilities'      => [
                    'performance_summary',
                    'hook_comparison',
                    'weak_point_analysis',
                    'top_content_report',
                    'engagement_review',
                ],
                'allowed_tools'     => [
                    'db_read',
                    'analytics_lookup',
                    'hook_lookup',
                    'queue_lookup',
                    'case_lookup',
                ],
                'default_template'  => 'performance_summary',
                'task_statuses'     => [
                    'submitted',
                    'working',
                    'input_needed',
                    'completed',
                    'failed',
                    'canceled',
                ],
            ],
        ];
    }
}

if (!function_exists('agent_definition')) {
    function agent_definition(string $agentId): ?array
    {
        $registry = agent_registry();
        return $registry[$agentId] ?? null;
    }
}

if (!function_exists('agent_exists')) {
    function agent_exists(string $agentId): bool
    {
        return agent_definition($agentId) !== null;
    }
}

if (!function_exists('enabled_agents')) {
    function enabled_agents(bool $visibleOnly = false): array
    {
        $agents = array_filter(
            agent_registry(),
            static function (array $agent) use ($visibleOnly): bool {
                if (($agent['enabled'] ?? false) !== true) {
                    return false;
                }

                if ($visibleOnly && ($agent['visible'] ?? false) !== true) {
                    return false;
                }

                return true;
            }
        );

        uasort(
            $agents,
            static fn(array $a, array $b): int => ($a['sort_order'] ?? 9999) <=> ($b['sort_order'] ?? 9999)
        );

        return $agents;
    }
}

if (!function_exists('agent_capabilities')) {
    function agent_capabilities(string $agentId): array
    {
        $agent = agent_definition($agentId);
        return $agent['capabilities'] ?? [];
    }
}

if (!function_exists('agent_allowed_tools')) {
    function agent_allowed_tools(string $agentId): array
    {
        $agent = agent_definition($agentId);
        return $agent['allowed_tools'] ?? [];
    }
}

if (!function_exists('agent_default_template')) {
    function agent_default_template(string $agentId): ?string
    {
        $agent = agent_definition($agentId);
        return $agent['default_template'] ?? null;
    }
}

if (!function_exists('agent_templates_path')) {
    function agent_templates_path(string $agentId): ?string
    {
        $agent = agent_definition($agentId);
        return $agent['templates_path'] ?? null;
    }
}
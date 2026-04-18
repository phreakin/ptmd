<?php
/**
 * PTMD / Social Ledger agent template helpers
 *
 * Purpose:
 * - Load agent task templates from inc/templates/agents/{agent}/
 * - Keep template discovery and access centralized
 * - Support template-driven Agent Console flows
 */

declare(strict_types=1);

require_once __DIR__ . '/agent_registry.php';

if (!function_exists('agent_template_slug_from_path')) {
    function agent_template_slug_from_path(string $path): string
    {
        return basename($path, '.php');
    }
}

if (!function_exists('agent_template_files')) {
    function agent_template_files(string $agentId): array
    {
        $basePath = agent_templates_path($agentId);
        if (!$basePath || !is_dir($basePath)) {
            return [];
        }

        $files = glob(rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php');
        return is_array($files) ? $files : [];
    }
}

if (!function_exists('agent_templates')) {
    function agent_templates(string $agentId): array
    {
        $files = agent_template_files($agentId);
        $templates = [];

        foreach ($files as $file) {
            $slug = agent_template_slug_from_path($file);
            $template = require $file;

            if (!is_array($template)) {
                continue;
            }

            if (!isset($template['template']) || !is_array($template['template'])) {
                $template['template'] = [];
            }

            if (!isset($template['template']['slug']) || $template['template']['slug'] === '') {
                $template['template']['slug'] = $slug;
            }

            if (!isset($template['template']['id']) || $template['template']['id'] === '') {
                $template['template']['id'] = $slug;
            }

            if (!isset($template['template']['name']) || $template['template']['name'] === '') {
                $template['template']['name'] = ucwords(str_replace(['_', '-'], ' ', $slug));
            }

            if (!isset($template['source'])) {
                $template['source'] = 'template';
            }

            if (!isset($template['inputs']) || !is_array($template['inputs'])) {
                $template['inputs'] = [];
            }

            $template['_meta'] = [
                'agent_id' => $agentId,
                'slug'     => $slug,
                'path'     => $file,
            ];

            $templates[$slug] = $template;
        }

        uasort($templates, static function (array $a, array $b): int {
            $aName = $a['template']['name'] ?? '';
            $bName = $b['template']['name'] ?? '';
            return strcasecmp($aName, $bName);
        });

        return $templates;
    }
}

if (!function_exists('agent_template')) {
    function agent_template(string $agentId, string $templateSlug): ?array
    {
        $templates = agent_templates($agentId);
        return $templates[$templateSlug] ?? null;
    }
}

if (!function_exists('all_agent_templates')) {
    function all_agent_templates(bool $visibleOnly = true): array
    {
        $agents = enabled_agents($visibleOnly);
        $all = [];

        foreach ($agents as $agentId => $agent) {
            $all[$agentId] = [
                'agent'     => $agent,
                'templates' => agent_templates($agentId),
            ];
        }

        return $all;
    }
}

if (!function_exists('agent_template_exists')) {
    function agent_template_exists(string $agentId, string $templateSlug): bool
    {
        return agent_template($agentId, $templateSlug) !== null;
    }
}

if (!function_exists('agent_template_names')) {
    function agent_template_names(string $agentId): array
    {
        $templates = agent_templates($agentId);
        $names = [];

        foreach ($templates as $slug => $template) {
            $names[$slug] = $template['template']['name'] ?? $slug;
        }

        return $names;
    }
}   
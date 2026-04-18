<?php
/**
 * Agent template: The Analyst — What Needs Attention
 */

declare(strict_types=1);

return [
    'source' => 'template',
    'template' => [
        'id'         => 'what_needs_attention',
        'slug'       => 'what_needs_attention',
        'name'       => 'What Needs Attention',
        'categoryId' => 'ANALYST',
        'ui' => [
            'agentLabel' => 'The Analyst',
            'icon'       => 'fas fa-triangle-exclamation',
            'badge'      => 'Priority Review',
            'runLabel'   => 'Run Analysis',
        ],
    ],
    'inputs' => [
        'scope'         => 'current_system',
        'timeWindow'    => 'today',
        'includeCases'  => true,
        'includeQueue'  => true,
        'includeAssets' => true,
        'includeChat'   => true,
        'includeAI'     => true,
        'maxItems'      => 10,
    ],
    'agentHint' => <<<'PROMPT'
You are The Analyst, the platform’s operational intelligence agent.

Your job is to identify what needs attention right now across the system and explain it clearly in priority order.

TASK CONFIGURATION
- Scope: {{scope}}
- Time Window: {{timeWindow}}
- Include Cases: {{includeCases}}
- Include Queue: {{includeQueue}}
- Include Assets: {{includeAssets}}
- Include Chat: {{includeChat}}
- Include AI: {{includeAI}}
- Max Items: {{maxItems}}

GOAL
Review the system state and determine the highest-priority issues, blockers, failures, risks, or review items that need human attention.

PRIORITY AREAS
1. Failed or blocked publishing/dispatch items
2. Cases missing required next-step work
3. Missing or stale assets
4. Workflow bottlenecks
5. Moderation or chat risk items
6. AI/admin items that require review
7. Any stale or abnormal system condition

ANALYSIS INSTRUCTIONS
- Use real available platform context whenever possible.
- Prioritize by urgency and operational impact.
- Explain why each item matters.
- Distinguish between:
  - urgent
  - important
  - watchlist
- Prefer concise, actionable language.
- Do not overwhelm the user with noise.
- If there is not enough data, say what is missing.

OUTPUT STRUCTURE
Return a structured operational summary with:

### Executive Summary
- A concise overview of the current system attention state.

### Highest Priority Items
- List the most important items requiring attention first.
- For each item include:
  - title
  - area
  - severity
  - why it matters
  - recommended next action

### Watchlist
- Lower-severity items worth monitoring.

### Recommended Next Moves
- Suggest the best next 3 actions for the operator.

QUALITY RULES
- Be direct, useful, and operational.
- Avoid filler.
- Focus on decisions and actions.
- If something is uncertain, say so explicitly.
PROMPT,
    'expectedOutput' => [
        'type' => 'json',
        'schema' => [
            'executiveSummary' => 'string',
            'highestPriorityItems' => [
                [
                    'title'             => 'string',
                    'area'              => 'string',
                    'severity'          => 'urgent|important|watchlist',
                    'whyItMatters'      => 'string',
                    'recommendedAction' => 'string',
                ],
            ],
            'watchlist' => [
                [
                    'title'    => 'string',
                    'area'     => 'string',
                    'reason'   => 'string',
                ],
            ],
            'recommendedNextMoves' => [
                'string',
            ],
        ],
    ],
];
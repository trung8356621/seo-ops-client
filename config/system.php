<?php

declare(strict_types=1);

/**
 * System AI / Workflow / Agent — strangler migration modes.
 *
 * Modes: legacy | shadow | remote
 * Capability-level overrides beat module-level defaults.
 * No global USE_NEW_AI flag.
 */
return [
    'default_mode' => env('SYSTEM_DEFAULT_MODE', 'legacy'),

    'modules' => [
        'ai' => env('SYSTEM_AI_MODE', 'legacy'),
        'workflow' => env('SYSTEM_WORKFLOW_MODE', 'legacy'),
        'agent' => env('SYSTEM_AGENT_MODE', 'legacy'),
    ],

    /**
     * Per-capability cutover. Examples:
     * seeding.comment.generate => remote
     * agent.chat => shadow
     */
    'capabilities' => [
        // Intentionally empty by default — production stays on legacy paths.
    ],

    'http' => [
        'base_url' => env('SYSTEM_API_BASE_URL', ''),
        'timeout_seconds' => (int) env('SYSTEM_API_TIMEOUT', 120),
    ],
];

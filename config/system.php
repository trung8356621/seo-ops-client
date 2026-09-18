<?php

declare(strict_types=1);

/**
 * System AI / Workflow / Agent — strangler migration modes.
 *
 * Modes: legacy | shadow | remote
 * Capability-level overrides beat module-level defaults.
 * No global USE_NEW_AI flag.
 *
 * Writing cutover (Content Project Rerun from Writing):
 *   SYSTEM_CAP_ARTICLE_CONTENT_GENERATE=remote
 * Rollback:
 *   SYSTEM_CAP_ARTICLE_CONTENT_GENERATE=legacy
 *   (or unset)
 */
$capabilities = [];
$articleContentMode = env('SYSTEM_CAP_ARTICLE_CONTENT_GENERATE');
if (is_string($articleContentMode) && trim($articleContentMode) !== '') {
    $capabilities['article.content.generate'] = trim($articleContentMode);
}

return [
    'default_mode' => env('SYSTEM_DEFAULT_MODE', 'legacy'),

    'modules' => [
        'ai' => env('SYSTEM_AI_MODE', 'legacy'),
        'workflow' => env('SYSTEM_WORKFLOW_MODE', 'legacy'),
        'agent' => env('SYSTEM_AGENT_MODE', 'legacy'),
    ],

    'capabilities' => $capabilities,

    'http' => [
        'base_url' => env('SYSTEM_API_BASE_URL', ''),
        'timeout_seconds' => (int) env('SYSTEM_API_TIMEOUT', 120),
    ],
];

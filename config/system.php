<?php

declare(strict_types=1);

/**
 * System AI / Workflow / Agent — strangler migration modes.
 *
 * Modes: legacy | shadow | remote
 * Capability-level overrides beat module-level defaults.
 * No global USE_NEW_AI flag.
 *
 * article.content.generate — ALL normal callers enter SystemAiClient.
 * Transport is selected inside DefaultSystemAiClient:
 *   legacy  = LegacyLocalAiTransport (in-process System boundary)
 *   shadow  = local authority + optional remote compare
 *   remote  = RemoteHttpAiTransport (fail-closed; no silent local fallback)
 *
 * Unset SYSTEM_CAP_ARTICLE_CONTENT_GENERATE still resolves to module/default
 * "legacy" (local System transport) — not "bypass SystemAiClient".
 *
 * Production may set SYSTEM_CAP_ARTICLE_CONTENT_GENERATE=remote for HTTP cutover.
 * Making remote the unset default is a separate decision.
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
        // Must cover long-form writing (provider fallback + generation). Run #310/#8553:
        // remote client timed out at 120s with 0 bytes while server still completed PR2108 (~121s+).
        // Align with Content Project article job timeout (default 900).
        'timeout_seconds' => (int) env(
            'SYSTEM_API_TIMEOUT',
            (int) env('CONTENT_PROJECT_ARTICLE_JOB_TIMEOUT_SECONDS', 900),
        ),
        // Service-to-service Bearer token for remote System AI HTTP calls.
        'service_token' => env('SYSTEM_API_TOKEN', ''),
    ],
];

<?php

declare(strict_types=1);

namespace App\System\Agent\Runtime;

use App\System\Agent\Contracts\AgentRuntimePort;
use App\System\Agent\Dto\AgentMessageRequest;
use App\System\Agent\Dto\AgentRunResult;
use App\System\Agent\Skills\SystemAgentSkillRegistry;
use App\System\Capability\SystemCapabilityRegistry;
use Illuminate\Support\Str;

/**
 * Capability-based Agent kernel path — no ContentProject / SEO imports.
 */
final class CapabilityBasedAgentRuntime implements AgentRuntimePort
{
    /** @var array<string, AgentRunResult> */
    private array $runs = [];

    public function __construct(
        private readonly SystemAgentSkillRegistry $skills,
        private readonly SystemCapabilityRegistry $capabilities,
    ) {}

    public function sendMessage(AgentMessageRequest $request): AgentRunResult
    {
        $conversationId = $request->conversationId !== null && $request->conversationId !== ''
            ? $request->conversationId
            : ('conv_'.Str::lower(Str::random(12)));
        $runId = 'run_'.Str::lower(Str::random(16));

        $events = [
            ['event' => 'message.accepted', 'data' => ['conversation_id' => $conversationId]],
            ['event' => 'planning.started', 'data' => []],
        ];

        $capabilityKey = trim((string) ($request->context['capability'] ?? ''));
        $skillKey = trim((string) ($request->context['skill'] ?? ''));

        if ($capabilityKey === '' && $skillKey !== '' && $this->skills->has($skillKey)) {
            $skill = $this->skills->get($skillKey);
            $capabilityKey = trim((string) ($skill['capability'] ?? $skillKey));
        }

        if ($capabilityKey === '') {
            $events[] = ['event' => 'planning.completed', 'data' => ['needs_clarification' => true]];
            $events[] = ['event' => 'assistant.completed', 'data' => [
                'text' => 'Please specify a registered skill or capability.',
            ]];
            $result = new AgentRunResult(
                runId: $runId,
                conversationId: $conversationId,
                status: 'needs_clarification',
                events: $events,
                output: ['clarifying_question' => true],
                meta: ['runtime' => 'capability_based', 'skills' => $this->skills->keys()],
            );
            $this->runs[$runId] = $result;

            return $result;
        }

        $events[] = ['event' => 'planning.completed', 'data' => ['capability' => $capabilityKey]];

        if (! $this->capabilities->has($capabilityKey)) {
            $events[] = ['event' => 'run.failed', 'data' => ['code' => 'capability_not_found']];
            $result = new AgentRunResult(
                runId: $runId,
                conversationId: $conversationId,
                status: 'failed',
                events: $events,
                errorCode: 'capability_not_found',
                errorMessage: "Capability [{$capabilityKey}] is not registered.",
                meta: ['runtime' => 'capability_based'],
            );
            $this->runs[$runId] = $result;

            return $result;
        }

        $events[] = ['event' => 'tool.started', 'data' => ['capability' => $capabilityKey]];
        $output = $this->capabilities->resolveHandler($capabilityKey)->handle(
            [
                'message' => $request->message,
                'input' => is_array($request->context['input'] ?? null) ? $request->context['input'] : [],
            ],
            array_merge($request->context, [
                'allow_domain_side_effects' => (bool) ($request->context['allow_domain_side_effects'] ?? true),
                'conversation_id' => $conversationId,
                'run_id' => $runId,
            ]),
        );
        $events[] = ['event' => 'tool.completed', 'data' => ['capability' => $capabilityKey]];
        $events[] = ['event' => 'assistant.completed', 'data' => ['output' => $output]];

        $result = new AgentRunResult(
            runId: $runId,
            conversationId: $conversationId,
            status: 'completed',
            events: $events,
            output: $output,
            meta: [
                'runtime' => 'capability_based',
                'capability' => $capabilityKey,
                'owner' => $this->capabilities->ownerOf($capabilityKey),
            ],
        );
        $this->runs[$runId] = $result;

        return $result;
    }

    public function getRun(string $runId): ?AgentRunResult
    {
        return $this->runs[$runId] ?? null;
    }
}

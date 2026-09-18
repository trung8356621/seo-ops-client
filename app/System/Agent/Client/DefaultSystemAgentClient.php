<?php

declare(strict_types=1);

namespace App\System\Agent\Client;

use App\System\Agent\Contracts\AgentRuntimePort;
use App\System\Agent\Contracts\SystemAgentClient;
use App\System\Agent\Dto\AgentMessageRequest;
use App\System\Agent\Dto\AgentRunResult;
use App\System\Agent\Runtime\CapabilityBasedAgentRuntime;
use App\System\Support\CapabilityModeResolver;
use App\System\Support\SystemExecutionMode;
use Illuminate\Support\Str;

final class DefaultSystemAgentClient implements SystemAgentClient
{
    /** @var array<string, array<string, mixed>> */
    private array $conversations = [];

    public function __construct(
        private readonly CapabilityModeResolver $modes,
        private readonly CapabilityBasedAgentRuntime $native,
        private readonly ?AgentRuntimePort $legacy = null,
    ) {}

    public function createConversation(array $context = []): array
    {
        $id = 'conv_'.Str::lower(Str::random(12));
        $this->conversations[$id] = $context;

        return ['conversation_id' => $id];
    }

    public function sendMessage(AgentMessageRequest $request): AgentRunResult
    {
        $mode = $this->modes->resolve('agent.chat', 'agent');

        // Target path: capability-based runtime (no ContentProject hardwire).
        // Legacy port optional for shadow/compare only when explicitly bound.
        $runtime = match ($mode) {
            SystemExecutionMode::Remote => $this->native,
            SystemExecutionMode::Shadow => $this->legacy ?? $this->native,
            SystemExecutionMode::Legacy => $this->legacy ?? $this->native,
        };

        if ($mode === SystemExecutionMode::Shadow && $this->legacy instanceof AgentRuntimePort) {
            $authority = $this->legacy->sendMessage($request);
            try {
                $this->native->sendMessage(new AgentMessageRequest(
                    conversationId: $request->conversationId,
                    message: $request->message,
                    context: array_merge($request->context, [
                        'allow_domain_side_effects' => false,
                        'shadow' => true,
                    ]),
                    correlation: $request->correlation,
                    idempotencyKey: $request->idempotencyKey,
                ));
            } catch (\Throwable) {
                // Shadow diagnostics only.
            }

            return $authority;
        }

        return $runtime->sendMessage($request);
    }

    public function getRun(string $runId): ?AgentRunResult
    {
        return $this->native->getRun($runId);
    }
}

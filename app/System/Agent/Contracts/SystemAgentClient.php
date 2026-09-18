<?php

declare(strict_types=1);

namespace App\System\Agent\Contracts;

use App\System\Agent\Dto\AgentMessageRequest;
use App\System\Agent\Dto\AgentRunResult;

interface SystemAgentClient
{
    /**
     * @param  array<string, mixed>  $context
     * @return array{conversation_id: string}
     */
    public function createConversation(array $context = []): array;

    public function sendMessage(AgentMessageRequest $request): AgentRunResult;

    public function getRun(string $runId): ?AgentRunResult;
}

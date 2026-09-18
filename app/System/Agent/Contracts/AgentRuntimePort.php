<?php

declare(strict_types=1);

namespace App\System\Agent\Contracts;

use App\System\Agent\Dto\AgentMessageRequest;
use App\System\Agent\Dto\AgentRunResult;

/**
 * Optional legacy bridge. New System Agent path must NOT require ContentProject implementation.
 */
interface AgentRuntimePort
{
    public function sendMessage(AgentMessageRequest $request): AgentRunResult;
}

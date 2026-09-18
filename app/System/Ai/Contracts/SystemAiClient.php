<?php

declare(strict_types=1);

namespace App\System\Ai\Contracts;

use App\System\Ai\Dto\AiExecutionRequest;
use App\System\Ai\Dto\AiExecutionResult;

/**
 * Stable SDK surface for System AI. Callers must not know legacy vs remote.
 */
interface SystemAiClient
{
    public function execute(AiExecutionRequest $request): AiExecutionResult;

    public function getExecution(string $id): ?AiExecutionResult;
}

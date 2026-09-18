<?php

declare(strict_types=1);

namespace App\System\Ai\Transport;

use App\System\Ai\Dto\AiExecutionRequest;
use App\System\Ai\Dto\AiExecutionResult;

interface AiTransport
{
    public function execute(AiExecutionRequest $request): AiExecutionResult;

    public function getExecution(string $id): ?AiExecutionResult;
}

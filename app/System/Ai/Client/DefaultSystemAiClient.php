<?php

declare(strict_types=1);

namespace App\System\Ai\Client;

use App\System\Ai\Contracts\SystemAiClient;
use App\System\Ai\Dto\AiExecutionRequest;
use App\System\Ai\Dto\AiExecutionResult;
use App\System\Ai\Transport\AiTransport;
use App\System\Ai\Transport\LegacyLocalAiTransport;
use App\System\Ai\Transport\RemoteHttpAiTransport;
use App\System\Ai\Transport\ShadowAiTransport;
use App\System\Support\CapabilityModeResolver;
use App\System\Support\SystemExecutionMode;

final class DefaultSystemAiClient implements SystemAiClient
{
    public function __construct(
        private readonly CapabilityModeResolver $modes,
        private readonly LegacyLocalAiTransport $local,
        private readonly ?RemoteHttpAiTransport $remote = null,
        private readonly ?\App\System\Capability\SystemCapabilityRegistry $capabilities = null,
    ) {}

    public function execute(AiExecutionRequest $request): AiExecutionResult
    {
        return $this->transportFor($request)->execute($request);
    }

    public function getExecution(string $id): ?AiExecutionResult
    {
        return $this->local->getExecution($id)
            ?? $this->remote?->getExecution($id);
    }

    private function transportFor(AiExecutionRequest $request): AiTransport
    {
        // HTTP controller entry must stay in-process to avoid RemoteHttp recursion.
        if ((bool) ($request->context['via_http_api'] ?? false)) {
            return $this->local;
        }

        $mode = $this->modes->resolve($request->capability, 'ai');

        return match ($mode) {
            SystemExecutionMode::Legacy => $this->local,
            SystemExecutionMode::Remote => $this->remote ?? $this->local,
            SystemExecutionMode::Shadow => new ShadowAiTransport(
                authority: $this->local,
                shadow: $this->remote ?? $this->local,
                capabilities: $this->capabilities ?? new \App\System\Capability\SystemCapabilityRegistry(),
            ),
        };
    }
}

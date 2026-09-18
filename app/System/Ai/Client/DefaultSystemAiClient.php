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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
            SystemExecutionMode::Remote => $this->remoteTransportOrFail($request),
            SystemExecutionMode::Shadow => new ShadowAiTransport(
                authority: $this->local,
                shadow: $this->remote ?? $this->failingRemotePlaceholder(),
                capabilities: $this->capabilities ?? new \App\System\Capability\SystemCapabilityRegistry(),
            ),
        };
    }

    private function remoteTransportOrFail(AiExecutionRequest $request): AiTransport
    {
        if ($this->remote instanceof RemoteHttpAiTransport) {
            return $this->remote;
        }

        Log::warning('system.ai.remote.failure', [
            'capability' => $request->capability,
            'error_code' => 'remote_transport_unavailable',
            'correlation_id' => $request->correlation['correlation_id'] ?? $request->correlation['id'] ?? null,
            'article_id' => $request->correlation['article_id'] ?? null,
            'project_item_id' => $request->correlation['project_item_id'] ?? null,
            'run_id' => $request->correlation['run_id'] ?? null,
        ]);

        return new class implements AiTransport
        {
            public function execute(AiExecutionRequest $request): AiExecutionResult
            {
                return new AiExecutionResult(
                    id: 'ai_'.Str::lower(Str::random(12)),
                    status: 'failed',
                    capability: $request->capability,
                    output: [],
                    trace: ['transport' => 'remote_unavailable'],
                    meta: ['mode' => 'remote'],
                    errorCode: 'remote_transport_unavailable',
                    errorMessage: 'Remote AI transport is unavailable (SYSTEM_API_BASE_URL / remote bind missing).',
                );
            }

            public function getExecution(string $id): ?AiExecutionResult
            {
                return null;
            }
        };
    }

    /**
     * Shadow must not break primary when remote is unbound — use a no-op failing transport.
     */
    private function failingRemotePlaceholder(): AiTransport
    {
        return new class implements AiTransport
        {
            public function execute(AiExecutionRequest $request): AiExecutionResult
            {
                return new AiExecutionResult(
                    id: 'shadow_remote_unavailable',
                    status: 'failed',
                    capability: $request->capability,
                    errorCode: 'remote_transport_unavailable',
                    errorMessage: 'Shadow remote transport unavailable.',
                );
            }

            public function getExecution(string $id): ?AiExecutionResult
            {
                return null;
            }
        };
    }
}

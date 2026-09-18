<?php

declare(strict_types=1);

namespace App\System\Ai\Transport;

use App\System\Ai\Dto\AiExecutionRequest;
use App\System\Ai\Dto\AiExecutionResult;
use App\System\Capability\SystemCapabilityRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Shadow: legacy writes/side-effects; new path resolve/execute when safe with NO domain writes.
 */
final class ShadowAiTransport implements AiTransport
{
    public function __construct(
        private readonly AiTransport $authority,
        private readonly AiTransport $shadow,
        private readonly SystemCapabilityRegistry $capabilities,
    ) {}

    public function execute(AiExecutionRequest $request): AiExecutionResult
    {
        $primary = $this->authority->execute($request);

        $definition = $this->capabilities->definition($request->capability);
        $safeForShadowExec = $definition === null || $definition->sideEffectFree
            || ! (bool) ($request->context['allow_domain_side_effects'] ?? true);

        $shadowRequest = new AiExecutionRequest(
            capability: $request->capability,
            input: $request->input,
            context: array_merge($request->context, [
                'allow_domain_side_effects' => false,
                'shadow' => true,
            ]),
            requirements: $request->requirements,
            correlation: array_merge($request->correlation, [
                'shadow_of' => $primary->id,
            ]),
            idempotencyKey: $request->idempotencyKey,
        );

        try {
            if ($safeForShadowExec || $definition?->sideEffectFree === true) {
                $shadowResult = $this->shadow->execute($shadowRequest);
            } else {
                $shadowResult = new AiExecutionResult(
                    id: 'shadow_skipped',
                    status: 'skipped',
                    capability: $request->capability,
                    meta: ['reason' => 'domain_side_effects_blocked'],
                );
            }

            Log::info('system.ai.shadow.compare', [
                'capability' => $request->capability,
                'authority_status' => $primary->status,
                'shadow_status' => $shadowResult->status,
                'authority_id' => $primary->id,
                'shadow_id' => $shadowResult->id,
            ]);

            if ($shadowResult->status === 'failed') {
                Log::warning('system.ai.remote.failure', [
                    'capability' => $request->capability,
                    'error_code' => $shadowResult->errorCode ?? 'shadow_failed',
                    'message' => $shadowResult->errorMessage,
                    'shadow_of' => $primary->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('system.ai.shadow.failed', [
                'capability' => $request->capability,
                'message' => $e->getMessage(),
            ]);
            Log::warning('system.ai.remote.failure', [
                'capability' => $request->capability,
                'error_code' => 'shadow_exception',
                'message' => $e->getMessage(),
                'shadow_of' => $primary->id,
            ]);
        }

        return $primary;
    }

    public function getExecution(string $id): ?AiExecutionResult
    {
        return $this->authority->getExecution($id);
    }
}

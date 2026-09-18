<?php

declare(strict_types=1);

namespace App\System\Ai\Transport;

use App\System\Ai\Contracts\AiTextExecutionPort;
use App\System\Ai\Dto\AiExecutionRequest;
use App\System\Ai\Dto\AiExecutionResult;
use App\System\Capability\SystemCapabilityRegistry;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * In-process path: capability handler → optional AiTextExecutionPort.
 * No HTTP hop. Used for legacy and remote-in-process (same app) modes.
 */
final class LegacyLocalAiTransport implements AiTransport
{
    /** @var array<string, AiExecutionResult> */
    private array $store = [];

    public function __construct(
        private readonly SystemCapabilityRegistry $capabilities,
        private readonly ?AiTextExecutionPort $textPort = null,
    ) {}

    public function execute(AiExecutionRequest $request): AiExecutionResult
    {
        $capability = trim($request->capability);
        if ($capability === '') {
            return $this->fail('', 'validation_error', 'capability is required');
        }

        $id = 'ai_'.Str::lower(Str::random(16));
        $started = microtime(true);

        try {
            if ($this->capabilities->has($capability)) {
                $handler = $this->capabilities->resolveHandler($capability);
                $context = array_merge($request->context, [
                    'requirements' => $request->requirements,
                    'correlation' => $request->correlation,
                    'idempotency_key' => $request->idempotencyKey,
                    'system_ai_text_port' => $this->textPort,
                    'allow_domain_side_effects' => (bool) ($request->context['allow_domain_side_effects'] ?? true),
                ]);
                $output = $handler->handle($request->input, $context);
            } elseif ($this->textPort instanceof AiTextExecutionPort) {
                $compiled = trim((string) ($request->input['compiled_prompt'] ?? ''));
                if ($compiled === '') {
                    throw new RuntimeException('compiled_prompt is required when no capability handler is registered.');
                }
                $generated = $this->textPort->generate(
                    $compiled,
                    (string) ($request->input['hook_key'] ?? $capability),
                    is_array($request->input['options'] ?? null) ? $request->input['options'] : [],
                );
                $output = [
                    'text' => (string) ($generated['text'] ?? ''),
                    'provider' => $generated['provider'] ?? null,
                    'model' => $generated['model'] ?? null,
                    'usage' => $generated['usage'] ?? null,
                    'physical_route' => $generated['physical_route'] ?? null,
                ];
            } else {
                throw new RuntimeException("No handler or AI text port for capability [{$capability}].");
            }

            $result = new AiExecutionResult(
                id: $id,
                status: 'completed',
                capability: $capability,
                output: $output,
                trace: [
                    'transport' => 'legacy_local',
                    'owner' => $this->capabilities->ownerOf($capability),
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                ],
                meta: [
                    'correlation' => $request->correlation,
                    'mode' => 'legacy_local',
                ],
            );
        } catch (Throwable $e) {
            $result = $this->fail($capability, 'execution_failed', $e->getMessage(), $id, [
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        }

        $this->store[$id] = $result;

        return $result;
    }

    public function getExecution(string $id): ?AiExecutionResult
    {
        return $this->store[$id] ?? null;
    }

    /**
     * @param  array<string, mixed>  $trace
     */
    private function fail(
        string $capability,
        string $code,
        string $message,
        ?string $id = null,
        array $trace = [],
    ): AiExecutionResult {
        return new AiExecutionResult(
            id: $id ?? ('ai_'.Str::lower(Str::random(16))),
            status: 'failed',
            capability: $capability,
            output: [],
            trace: array_merge(['transport' => 'legacy_local'], $trace),
            meta: [],
            errorCode: $code,
            errorMessage: $message,
        );
    }
}

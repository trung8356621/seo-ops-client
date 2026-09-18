<?php

declare(strict_types=1);

namespace App\System\Ai\Transport;

use App\System\Ai\Contracts\AiTextExecutionPort;
use App\System\Ai\Dto\AiExecutionRequest;
use App\System\Ai\Dto\AiExecutionResult;
use App\System\Capability\SystemCapabilityRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * In-process path: capability handler → optional AiTextExecutionPort.
 * No HTTP hop. Used for legacy and remote-in-process (same app) modes.
 */
final class LegacyLocalAiTransport implements AiTransport
{
    public const EXECUTION_CACHE_PREFIX = 'system_ai_execution:';

    public const EXECUTION_CACHE_TTL_SECONDS = 3600;

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
        $viaHttp = (bool) ($request->context['via_http_api'] ?? false);

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

            if ($viaHttp && is_array($output)) {
                $output['via_http_api'] = true;
            }

            $result = new AiExecutionResult(
                id: $id,
                status: 'completed',
                capability: $capability,
                output: $output,
                trace: [
                    'transport' => $viaHttp ? 'http_local' : 'legacy_local',
                    'owner' => $this->capabilities->ownerOf($capability),
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'via_http_api' => $viaHttp,
                ],
                meta: [
                    'correlation' => $request->correlation,
                    'mode' => $viaHttp ? 'http_api' : 'legacy_local',
                    'via_http_api' => $viaHttp,
                ],
            );
        } catch (Throwable $e) {
            $result = $this->fail($capability, 'execution_failed', $e->getMessage(), $id, [
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'via_http_api' => $viaHttp,
            ]);
        }

        $this->store[$id] = $result;
        $this->persistExecutionEnvelope($id, $result);

        return $result;
    }

    public function getExecution(string $id): ?AiExecutionResult
    {
        if (isset($this->store[$id])) {
            return $this->store[$id];
        }

        $cached = Cache::get(self::EXECUTION_CACHE_PREFIX.$id);
        if (! is_array($cached)) {
            return null;
        }

        $result = AiExecutionResult::fromArray($cached);
        $this->store[$id] = $result;

        return $result;
    }

    private function persistExecutionEnvelope(string $id, AiExecutionResult $result): void
    {
        try {
            Cache::put(
                self::EXECUTION_CACHE_PREFIX.$id,
                $result->toArray(),
                self::EXECUTION_CACHE_TTL_SECONDS,
            );
        } catch (Throwable) {
            // Best-effort cross-request GET; in-memory store remains for same process.
        }
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

<?php

declare(strict_types=1);

namespace App\System\Workflow\Transport;

use App\System\Workflow\Contracts\WorkflowRuntimePort;
use App\System\Workflow\Dto\WorkflowRunRequest;
use App\System\Workflow\Dto\WorkflowRunResult;
use App\System\Workflow\Nodes\WorkflowNodeRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Coarse-grained local runtime: validate graph + optionally delegate to WorkflowRuntimePort.
 * Does not HTTP-call per node.
 */
final class LegacyLocalWorkflowTransport
{
    public const RUN_CACHE_PREFIX = 'system_workflow_run:';

    /** TTL for GET /workflow-runs/{id}. Multi-instance deploys must use a shared cache driver. */
    public const RUN_CACHE_TTL_SECONDS = 3600;

    /** @var array<string, WorkflowRunResult> */
    private array $runs = [];

    public function __construct(
        private readonly WorkflowNodeRegistry $nodes,
        private readonly ?WorkflowRuntimePort $runtime = null,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     * @return array{valid: bool, errors: list<string>, meta?: array<string, mixed>}
     */
    public function validate(array $definition): array
    {
        if ($this->runtime instanceof WorkflowRuntimePort) {
            return $this->runtime->validate($definition);
        }

        return $this->validateGraphLocally($definition);
    }

    public function run(WorkflowRunRequest $request): WorkflowRunResult
    {
        if ($this->runtime instanceof WorkflowRuntimePort) {
            $result = $this->runtime->run($request);
            $this->remember($result);

            return $result;
        }

        $definition = $request->definition;
        if ($definition === null && $request->definitionId !== null) {
            throw new RuntimeException('WorkflowRuntimePort is required to load definition_id from legacy storage.');
        }
        if (! is_array($definition)) {
            return $this->fail('validation_error', 'definition or definition_id is required');
        }

        $validation = $this->validateGraphLocally($definition);
        if (! $validation['valid']) {
            return $this->fail('invalid_definition', implode('; ', $validation['errors']));
        }

        $id = 'wf_'.Str::lower(Str::random(16));
        $steps = [];
        $artifacts = [];
        $nodes = is_array($definition['nodes'] ?? null) ? $definition['nodes'] : [];
        $ownerUserId = (int) ($request->correlation['owner_user_id'] ?? $request->context['owner_user_id'] ?? 0);

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $type = trim((string) ($node['type'] ?? $node['data']['type'] ?? ''));
            $nodeId = (string) ($node['id'] ?? Str::random(8));
            if ($type === '' || ! $this->nodes->has($type)) {
                $steps[$nodeId] = [
                    'status' => 'skipped',
                    'reason' => $type === '' ? 'missing_type' : 'unregistered_node_type',
                    'type' => $type,
                ];
                continue;
            }

            $output = $this->nodes->resolve($type)->execute(
                is_array($node['data'] ?? null) ? $node['data'] : [],
                array_merge($request->context, [
                    'input' => $request->input,
                    'allow_domain_side_effects' => (bool) ($request->context['allow_domain_side_effects'] ?? true),
                ]),
            );
            $steps[$nodeId] = ['status' => 'completed', 'type' => $type, 'output' => $output];
            $artifacts[$nodeId] = $output;
        }

        $result = new WorkflowRunResult(
            id: $id,
            status: 'completed',
            steps: $steps,
            artifacts: $artifacts,
            meta: [
                'transport' => 'legacy_local',
                'node_types' => $this->nodes->types(),
                'owner_user_id' => $ownerUserId > 0 ? $ownerUserId : null,
                'definition_id' => $request->definitionId,
                'execution_mode' => $request->executionMode,
                'execution_scope' => $request->executionScope,
            ],
        );
        $this->remember($result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function getRun(string $id, array $context = []): ?WorkflowRunResult
    {
        $ownerRequired = (int) ($context['owner_user_id'] ?? 0);

        if (isset($this->runs[$id])) {
            $result = $this->runs[$id];
            if (! $this->ownerMatches($result, $ownerRequired)) {
                return null;
            }

            return $result;
        }

        $cached = Cache::get(self::RUN_CACHE_PREFIX.$id);
        if (! is_array($cached)) {
            return null;
        }

        $result = WorkflowRunResult::fromArray($cached);
        if (! $this->ownerMatches($result, $ownerRequired)) {
            return null;
        }

        $this->runs[$id] = $result;

        return $result;
    }

    public function cancel(string $id): WorkflowRunResult
    {
        if ($this->runtime instanceof WorkflowRuntimePort) {
            return $this->runtime->cancel($id);
        }

        return $this->fail('not_supported', 'cancel requires WorkflowRuntimePort', $id);
    }

    public function retry(string $id): WorkflowRunResult
    {
        if ($this->runtime instanceof WorkflowRuntimePort) {
            return $this->runtime->retry($id);
        }

        return $this->fail('not_supported', 'retry requires WorkflowRuntimePort', $id);
    }

    public function resume(string $id): WorkflowRunResult
    {
        if ($this->runtime instanceof WorkflowRuntimePort) {
            return $this->runtime->resume($id);
        }

        return $this->fail('not_supported', 'resume requires WorkflowRuntimePort', $id);
    }

    private function remember(WorkflowRunResult $result): void
    {
        $this->runs[$result->id] = $result;
        $this->persistRunEnvelope($result);
    }

    private function persistRunEnvelope(WorkflowRunResult $result): void
    {
        try {
            Cache::put(
                self::RUN_CACHE_PREFIX.$result->id,
                $result->toArray(),
                self::RUN_CACHE_TTL_SECONDS,
            );
        } catch (Throwable) {
            // Best-effort cross-request GET; in-memory store remains for same process.
        }
    }

    private function ownerMatches(WorkflowRunResult $result, int $ownerRequired): bool
    {
        // In-process callers without owner filter (ownerRequired=0) may read memory/cache.
        // HTTP API always passes owner_user_id > 0.
        if ($ownerRequired <= 0) {
            return true;
        }

        $stored = (int) ($result->meta['owner_user_id'] ?? 0);

        return $stored > 0 && $stored === $ownerRequired;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{valid: bool, errors: list<string>, meta?: array<string, mixed>}
     */
    private function validateGraphLocally(array $definition): array
    {
        $errors = [];
        $nodes = $definition['nodes'] ?? null;
        $edges = $definition['edges'] ?? ($definition['connections'] ?? []);
        if (! is_array($nodes) || $nodes === []) {
            $errors[] = 'definition.nodes must be a non-empty array';
        }
        if (! is_array($edges)) {
            $errors[] = 'definition.edges must be an array';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'meta' => [
                'registered_node_types' => $this->nodes->types(),
            ],
        ];
    }

    private function fail(string $code, string $message, ?string $id = null): WorkflowRunResult
    {
        $result = new WorkflowRunResult(
            id: $id ?? ('wf_'.Str::lower(Str::random(16))),
            status: 'failed',
            errorCode: $code,
            errorMessage: $message,
            meta: ['transport' => 'legacy_local'],
        );
        $this->remember($result);

        return $result;
    }
}

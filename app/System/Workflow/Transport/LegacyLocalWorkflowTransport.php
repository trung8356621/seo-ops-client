<?php

declare(strict_types=1);

namespace App\System\Workflow\Transport;

use App\System\Workflow\Contracts\WorkflowRuntimePort;
use App\System\Workflow\Dto\WorkflowRunRequest;
use App\System\Workflow\Dto\WorkflowRunResult;
use App\System\Workflow\Nodes\WorkflowNodeRegistry;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Coarse-grained local runtime: validate graph + optionally delegate to WorkflowRuntimePort.
 * Does not HTTP-call per node.
 */
final class LegacyLocalWorkflowTransport
{
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
            $this->runs[$result->id] = $result;

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
            meta: ['transport' => 'legacy_local', 'node_types' => $this->nodes->types()],
        );
        $this->runs[$id] = $result;

        return $result;
    }

    public function getRun(string $id): ?WorkflowRunResult
    {
        return $this->runs[$id] ?? null;
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
        $this->runs[$result->id] = $result;

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace App\System\Workflow\Contracts;

use App\System\Workflow\Dto\WorkflowRunRequest;
use App\System\Workflow\Dto\WorkflowRunResult;

/**
 * Implemented by ai-prompt legacy adapter. System does not import SeoTask/TaskWorkflowTestRunner.
 */
interface WorkflowRuntimePort
{
    /**
     * @param  array<string, mixed>  $definition
     * @return array{valid: bool, errors: list<string>, meta?: array<string, mixed>}
     */
    public function validate(array $definition): array;

    /**
     * Load opaque flow_data by legacy seo_tasks id (no table rename).
     *
     * @return array<string, mixed>|null
     */
    public function loadDefinition(int $definitionId): ?array;

    public function run(WorkflowRunRequest $request): WorkflowRunResult;

    public function cancel(string $runId): WorkflowRunResult;

    public function retry(string $runId): WorkflowRunResult;

    public function resume(string $runId): WorkflowRunResult;
}

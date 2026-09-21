<?php

declare(strict_types=1);

namespace App\System\Workflow\Contracts;

use App\System\Workflow\Dto\WorkflowRunRequest;
use App\System\Workflow\Dto\WorkflowRunResult;

interface SystemWorkflowClient
{
    /**
     * @param  array<string, mixed>  $definition
     * @return array{valid: bool, errors: list<string>, meta?: array<string, mixed>}
     */
    public function validate(array $definition): array;

    public function run(WorkflowRunRequest $request): WorkflowRunResult;

    /**
     * @param  array<string, mixed>  $context  Optional owner_user_id / via_http_api for scoped GET.
     */
    public function getRun(string $id, array $context = []): ?WorkflowRunResult;

    public function cancel(string $id): WorkflowRunResult;

    public function retry(string $id): WorkflowRunResult;

    public function resume(string $id): WorkflowRunResult;
}

<?php

declare(strict_types=1);

namespace App\System\Workflow\Client;

use App\System\Support\CapabilityModeResolver;
use App\System\Support\SystemExecutionMode;
use App\System\Workflow\Contracts\SystemWorkflowClient;
use App\System\Workflow\Dto\WorkflowRunRequest;
use App\System\Workflow\Dto\WorkflowRunResult;
use App\System\Workflow\Transport\LegacyLocalWorkflowTransport;

final class DefaultSystemWorkflowClient implements SystemWorkflowClient
{
    public function __construct(
        private readonly CapabilityModeResolver $modes,
        private readonly LegacyLocalWorkflowTransport $local,
    ) {}

    public function validate(array $definition): array
    {
        return $this->local->validate($definition);
    }

    public function run(WorkflowRunRequest $request): WorkflowRunResult
    {
        // Mode reserved for future remote HTTP; same-process uses local coarse runtime.
        $this->modes->resolve(
            isset($request->correlation['capability']) ? (string) $request->correlation['capability'] : null,
            'workflow',
        );

        return $this->local->run($request);
    }

    public function getRun(string $id): ?WorkflowRunResult
    {
        return $this->local->getRun($id);
    }

    public function cancel(string $id): WorkflowRunResult
    {
        return $this->local->cancel($id);
    }

    public function retry(string $id): WorkflowRunResult
    {
        return $this->local->retry($id);
    }

    public function resume(string $id): WorkflowRunResult
    {
        return $this->local->resume($id);
    }
}

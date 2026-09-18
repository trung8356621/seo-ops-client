<?php

declare(strict_types=1);

namespace App\System\Workflow\Nodes;

use InvalidArgumentException;
use RuntimeException;

final class WorkflowNodeRegistry
{
    /** @var array<string, class-string<WorkflowNodeHandler>|WorkflowNodeHandler> */
    private array $handlers = [];

    /**
     * @param  class-string<WorkflowNodeHandler>|WorkflowNodeHandler  $handler
     */
    public function register(string $nodeType, string|WorkflowNodeHandler $handler): void
    {
        $nodeType = trim($nodeType);
        if ($nodeType === '') {
            throw new InvalidArgumentException('nodeType must not be empty.');
        }
        if (isset($this->handlers[$nodeType])) {
            throw new InvalidArgumentException("Workflow node type [{$nodeType}] already registered.");
        }
        $this->handlers[$nodeType] = $handler;
    }

    public function has(string $nodeType): bool
    {
        return isset($this->handlers[trim($nodeType)]);
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->handlers);
    }

    public function resolve(string $nodeType): WorkflowNodeHandler
    {
        $handler = $this->handlers[trim($nodeType)] ?? null;
        if ($handler === null) {
            throw new RuntimeException("Workflow node type [{$nodeType}] is not registered.");
        }
        if ($handler instanceof WorkflowNodeHandler) {
            return $handler;
        }
        $resolved = app($handler);
        if (! $resolved instanceof WorkflowNodeHandler) {
            throw new RuntimeException("Workflow node [{$nodeType}] must implement WorkflowNodeHandler.");
        }

        return $resolved;
    }
}

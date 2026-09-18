<?php

declare(strict_types=1);

namespace App\System\Workflow\Nodes;

/**
 * Generic node handler — domain addons register by node type / capability key.
 *
 * @phpstan-type NodeInput array<string, mixed>
 * @phpstan-type NodeOutput array<string, mixed>
 */
interface WorkflowNodeHandler
{
    public function nodeType(): string;

    /**
     * @param  NodeInput  $input
     * @param  array<string, mixed>  $context
     * @return NodeOutput
     */
    public function execute(array $input, array $context = []): array;
}

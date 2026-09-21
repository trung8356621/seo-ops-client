<?php

declare(strict_types=1);

namespace App\System\Workflow\Dto;

/**
 * Graph execution shape for System Workflow runs.
 * Distinct from SystemExecutionMode (legacy|shadow|remote transport selection).
 */
enum WorkflowExecutionMode: string
{
    case FullRun = 'full_run';
    case FromNode = 'from_node';
    case SingleStep = 'single_step';

    public static function tryParse(?string $raw): ?self
    {
        if ($raw === null) {
            return null;
        }

        $normalized = strtolower(trim($raw));
        if ($normalized === '') {
            return null;
        }

        return self::tryFrom($normalized);
    }
}

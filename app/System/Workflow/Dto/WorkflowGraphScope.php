<?php

declare(strict_types=1);

namespace App\System\Workflow\Dto;

/**
 * Generic graph-run constraint for System Workflow.
 * Wire values are transportable; domain adapters map to product scopes.
 * Distinct from WorkflowExecutionMode (full_run / from_node / single_step).
 */
enum WorkflowGraphScope: string
{
    case Full = 'full';
    case OutlineVocabulary = 'outline_vocabulary';

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

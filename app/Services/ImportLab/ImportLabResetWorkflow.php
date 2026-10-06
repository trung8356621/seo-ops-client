<?php

declare(strict_types=1);

namespace App\Services\ImportLab;

interface ImportLabResetWorkflow
{
    /**
     * @param  array<string, array<string, mixed>>  $connections
     * @return array<string, mixed>
     */
    public function run(array $connections): array;
}

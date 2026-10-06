<?php

declare(strict_types=1);

namespace App\Services\ImportLab;

interface ImportLabDatabaseAdmin
{
    /** @param array<string, array<string, mixed>> $connections */
    public function recreate(array $connections): void;
}

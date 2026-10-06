<?php

declare(strict_types=1);

namespace App\Services\ImportLab;

interface ImportLabRuntime
{
    public function clearConfig(): void;

    public function migrate(): void;

    public function bootstrapServices(): void;
}

<?php

declare(strict_types=1);

namespace App\Services\ImportLab;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

final class ArtisanImportLabRuntime implements ImportLabRuntime
{
    public function clearConfig(): void
    {
        if (Artisan::call('config:clear') !== 0) {
            throw new RuntimeException('Config cache clear failed: '.trim(Artisan::output()));
        }
    }

    public function migrate(): void
    {
        try {
            $status = Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            throw new RuntimeException(trim($e->getMessage()), 0, $e);
        }
        if ($status !== 0) {
            throw new RuntimeException(trim(Artisan::output()));
        }
    }

    public function bootstrapServices(): void
    {
        if (Artisan::call('service:simulate', ['--all' => true, '--force' => true]) !== 0) {
            throw new RuntimeException('Service bootstrap failed: '.trim(Artisan::output()));
        }
    }
}

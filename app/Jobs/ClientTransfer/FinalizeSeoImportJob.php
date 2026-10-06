<?php

declare(strict_types=1);

namespace App\Jobs\ClientTransfer;

use App\Models\ClientTransferRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class FinalizeSeoImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $runId)
    {
        $this->onQueue('client-transfer');
    }

    public function handle(): void
    {
        $run = ClientTransferRun::query()->where('run_id', $this->runId)->first();
        if ($run === null || $run->shouldStopTransfer()) {
            return;
        }

        $stagingDir = storage_path("app/client-transfer/staging_import_{$this->runId}");

        self::deleteDir($stagingDir);

        $run->markCompleted(retryPackagePath: $run->retry_package_path);
    }

    public function failed(\Throwable $e): void
    {
        $run = ClientTransferRun::query()->where('run_id', $this->runId)->first();
        if ($run !== null && ! $run->isCancelled()) {
            $run->markFailed($e->getMessage());
        }
    }

    private static function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($p) ? self::deleteDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}

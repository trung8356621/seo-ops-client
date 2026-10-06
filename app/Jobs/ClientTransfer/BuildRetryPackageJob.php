<?php

declare(strict_types=1);

namespace App\Jobs\ClientTransfer;

use App\Models\ClientTransferRun;
use App\Services\ClientTransfer\DatasetRegistry;
use App\Services\ClientTransfer\QuarantinePackageBuilder;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class BuildRetryPackageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $runId)
    {
        $this->onQueue('client-transfer');
    }

    public function handle(DatasetRegistry $registry): void
    {
        $run = ClientTransferRun::query()->where('run_id', $this->runId)->first();
        if ($run === null || $run->shouldStopTransfer()) {
            return;
        }

        $run->update([
            'phase' => 'quarantine',
        ]);

        $refMap = new ReferenceMap($this->runId);

        if ($refMap->countFailures() > 0) {
            $stagingDir = storage_path("app/client-transfer/staging_import_{$this->runId}");
            $quarantineDir = storage_path('app/client-transfer/quarantine');
            if (! is_dir($quarantineDir)) {
                mkdir($quarantineDir, 0755, true);
            }

            $destZip = $quarantineDir.DIRECTORY_SEPARATOR.'seo-import-failed-'.date('Ymd-His').'-'.$this->runId.'.zip';
            $retryPath = QuarantinePackageBuilder::buildFromRefMap($this->runId, $refMap, $stagingDir, $destZip, $registry);

            $run->update([
                'retry_package_path' => $retryPath,
            ]);
        }

        $run->refresh();
        if ($run->shouldStopTransfer()) {
            return;
        }

        FinalizeSeoImportJob::dispatch($this->runId)->onQueue('client-transfer');
    }

    public function failed(\Throwable $e): void
    {
        $run = ClientTransferRun::query()->where('run_id', $this->runId)->first();
        if ($run !== null && ! $run->isCancelled()) {
            $run->markFailed($e->getMessage());
        }
    }
}

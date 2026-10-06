<?php

declare(strict_types=1);

namespace App\Jobs\ClientTransfer;

use App\Models\ClientTransferRun;
use App\Services\ClientTransfer\ClientTransferImporter;
use App\Services\ClientTransfer\DatasetRegistry;
use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\ReferenceMap;
use App\Services\ClientTransfer\Support\ZipArchiveManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class PrepareSeoImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $runId,
        public readonly string $uploadedZipPath,
    ) {
        $this->onQueue('client-transfer');
    }

    public function handle(DatasetRegistry $registry): void
    {
        $run = ClientTransferRun::query()->where('run_id', $this->runId)->firstOrFail();
        $run->update([
            'status' => 'running',
            'phase' => 'prepare',
            'started_at' => $run->started_at ?? now(),
        ]);

        // 1. Extract and validate package
        $stagingDir = storage_path("app/client-transfer/staging_import_{$this->runId}");
        if (is_dir($stagingDir)) {
            self::deleteDir($stagingDir);
        }

        $res = ZipArchiveManager::extractAndValidate($this->uploadedZipPath, $stagingDir);
        $manifest = $res['manifest'];

        // 2. Strict connection, schema, and empty-target validation
        $importer = new ClientTransferImporter($registry);
        try {
            $importer->assertTargetReadyForImport($manifest);
        } catch (\Throwable $e) {
            $run->markFailed($e->getMessage());
            self::deleteDir($stagingDir);

            throw $e;
        }

        // 3. Initialize fresh ReferenceMap SQLite
        $refMapPath = storage_path("app/client-transfer/refmap_{$this->runId}.sqlite");
        if (file_exists($refMapPath)) {
            @unlink($refMapPath);
        }
        $refMap = new ReferenceMap($this->runId);
        if ($manifest->isRetryData() && $manifest->originalImportRunId() !== null) {
            $refMap->importReferencesFrom(storage_path('app/client-transfer/refmap_'.$manifest->originalImportRunId().'.sqlite'));
        }
        $importRun = new ImportRun($this->runId, $refMap);

        // Calculate total records
        $totalRecords = 0;
        foreach ($manifest->datasets as $dKey => $dManifest) {
            $totalRecords += $dManifest->count;
            $importRun->initDataset($dKey, $dManifest->count);
        }

        // Topologically sorted datasets
        $sortedDatasets = $registry->sortedDatasets();
        $datasetsQueue = [];
        foreach ($sortedDatasets as $ds) {
            if (isset($manifest->datasets[$ds->key()])) {
                $datasetsQueue[] = $ds->key();
            }
        }

        $run->update([
            'total_records' => $totalRecords,
            'processed_records' => 0,
            'metadata' => array_merge($run->metadata ?? [], [
                'datasets_queue' => $datasetsQueue,
                'staging_dir' => $stagingDir,
                'zip_path' => $this->uploadedZipPath,
            ]),
        ]);

        if (empty($datasetsQueue)) {
            FinalizeSeoImportJob::dispatch($this->runId)->onQueue('client-transfer');

            return;
        }

        ImportDatasetSliceJob::dispatch(
            runId: $this->runId,
            datasetKey: $datasetsQueue[0],
            datasetQueueIndex: 0,
            partIndex: 0,
            byteOffset: 0,
            recordIndex: 0,
        )->onQueue('client-transfer');
    }

    public function failed(\Throwable $e): void
    {
        ClientTransferRun::query()->where('run_id', $this->runId)->first()?->markFailed($e->getMessage());
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

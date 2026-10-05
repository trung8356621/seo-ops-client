<?php

declare(strict_types=1);

namespace App\Jobs\ClientTransfer;

use App\Models\ClientTransferRun;
use App\Services\ClientTransfer\DatasetRegistry;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

final class RollbackSeoImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $runId)
    {
        $this->onQueue('client-transfer');
    }

    public function handle(DatasetRegistry $registry): void
    {
        $run = ClientTransferRun::query()->where('run_id', $this->runId)->firstOrFail();
        if (! $run->canRollback() && $run->status !== 'rolling_back') {
            throw new RuntimeException("Import run [{$this->runId}] cannot be rolled back from status [{$run->status}].");
        }

        $run->update(['status' => 'rolling_back', 'phase' => 'rollback']);

        try {
            $refMapPath = storage_path("app/client-transfer/refmap_{$this->runId}.sqlite");
            if (! file_exists($refMapPath)) {
                throw new RuntimeException("Import journal is missing for run [{$this->runId}].");
            }

            $refMap = new ReferenceMap($this->runId);
            $datasets = array_reverse($registry->sortedDatasets());
            foreach ($datasets as $dataset) {
                $run->update(['phase' => 'rollback:'.$dataset->key(), 'current_dataset' => $dataset->key()]);
                foreach ($refMap->getCreatedRecords($dataset->key()) as $record) {
                    $dataset->rollbackImportedRecord($record['target_key'], $record['context']);
                }
            }

            $retryPath = (string) ($run->retry_package_path ?? '');
            if ($retryPath !== '' && is_file($retryPath)) {
                @unlink($retryPath);
            }

            self::deleteDir(storage_path("app/client-transfer/staging_import_{$this->runId}"));
            self::deleteDir(storage_path("app/client-transfer/staging_quarantine_{$this->runId}"));
            $refMap->cleanup();

            $run->update([
                'status' => 'rolled_back',
                'phase' => 'rollback_completed',
                'current_dataset' => null,
                'retry_package_path' => null,
                'error_message' => null,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $run->markRollbackFailed($e->getMessage());

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        ClientTransferRun::query()->where('run_id', $this->runId)->first()?->markRollbackFailed($e->getMessage());
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
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? self::deleteDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs\ClientTransfer;

use App\Models\ClientTransferRun;
use App\Services\ClientTransfer\DatasetRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class PrepareSeoExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $runId)
    {
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

        $stagingDir = storage_path("app/client-transfer/staging_export_{$this->runId}");
        if (is_dir($stagingDir)) {
            self::deleteDir($stagingDir);
        }
        mkdir($stagingDir, 0755, true);

        $sortedDatasets = $registry->sortedDatasets();
        $datasetKeys = array_map(fn ($d) => $d->key(), $sortedDatasets);

        // Store state file in staging directory
        file_put_contents(
            $stagingDir . DIRECTORY_SEPARATOR . 'export_state.json',
            json_encode([
                'dataset_manifests' => [],
                'counts' => [],
            ], JSON_PRETTY_PRINT)
        );

        $run->update([
            'metadata' => array_merge($run->metadata ?? [], [
                'datasets_queue' => $datasetKeys,
            ]),
        ]);

        if (empty($datasetKeys)) {
            FinalizeSeoExportJob::dispatch($this->runId)->onQueue('client-transfer');
            return;
        }

        ExportDatasetSliceJob::dispatch(
            runId: $this->runId,
            datasetKey: $datasetKeys[0],
            datasetQueueIndex: 0,
            afterId: 0,
            partIndex: 1,
            accumulatedDatasetCount: 0,
            accumulatedParts: []
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
            $p = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($p) ? self::deleteDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}

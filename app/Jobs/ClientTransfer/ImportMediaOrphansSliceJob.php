<?php

declare(strict_types=1);

namespace App\Jobs\ClientTransfer;

use App\Models\ClientTransferRun;
use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\MediaBinaryManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ImportMediaOrphansSliceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $runId,
        public readonly int $mediaDatasetQueueIndex,
        public readonly int $byteOffset = 0,
    ) {
        $this->onQueue('client-transfer');
    }

    public function handle(): void
    {
        $run = ClientTransferRun::query()->where('run_id', $this->runId)->first();
        if ($run === null || $run->shouldStopTransfer()) {
            return;
        }

        $stagingDir = storage_path("app/client-transfer/staging_import_{$this->runId}");
        $refMap = new ReferenceMap($this->runId);
        $importRun = new ImportRun($this->runId, $refMap);
        $manager = new MediaBinaryManager($stagingDir);
        $manager->loadState();

        $run->update([
            'phase' => 'import:media_orphans',
            'current_dataset' => 'media',
        ]);

        $slice = $manager->importOrphanSlice($refMap, $importRun, $this->byteOffset, 1);
        $manager->persistImportStatsToRun($this->runId);
        $manager->saveState();

        $stats = $importRun->getDatasetStats()['media'] ?? [];
        $run->update([
            'failed_count' => $run->failed_count + (int) ($stats['failed'] ?? 0),
            'processed_records' => $run->processed_records + $slice['processed'],
        ]);

        $run->refresh();
        if ($run->shouldStopTransfer()) {
            return;
        }

        if ($slice['has_more']) {
            self::dispatch($this->runId, $this->mediaDatasetQueueIndex, $slice['next_offset'])->onQueue('client-transfer');

            return;
        }

        ImportDatasetSliceJob::dispatchAfterDataset($this->runId, $this->mediaDatasetQueueIndex);
    }

    public function failed(\Throwable $e): void
    {
        $run = ClientTransferRun::query()->where('run_id', $this->runId)->first();
        if ($run !== null && ! $run->isCancelled()) {
            $run->markFailed($e->getMessage());
        }
    }
}

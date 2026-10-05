<?php

declare(strict_types=1);

namespace App\Jobs\ClientTransfer;

use App\Models\ClientTransferRun;
use App\Services\ClientTransfer\DatasetRegistry;
use App\Services\ClientTransfer\Manifest\DatasetManifest;
use App\Services\ClientTransfer\Manifest\PartManifest;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\NdjsonPartWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ExportDatasetSliceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  list<array{file: string, count: int, sha256: string, bytes: int}>  $accumulatedParts
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $datasetKey,
        public readonly int $datasetQueueIndex,
        public readonly int $afterId = 0,
        public readonly int $partIndex = 1,
        public readonly int $accumulatedDatasetCount = 0,
        public readonly array $accumulatedParts = [],
    ) {
        $this->onQueue('client-transfer');
    }

    public function handle(DatasetRegistry $registry): void
    {
        $run = ClientTransferRun::query()->where('run_id', $this->runId)->firstOrFail();
        $dataset = $registry->get($this->datasetKey);
        if ($dataset === null) {
            $this->advanceToNextDataset($run);
            return;
        }

        $stagingDir = storage_path("app/client-transfer/staging_export_{$this->runId}");
        $blobDir = $stagingDir . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'blobs';
        $blobs = new BlobManager($blobDir);

        $datasetDir = $stagingDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dataset->relativeSubdir());
        $writer = new NdjsonPartWriter(
            directory: $datasetDir,
            relativeSubdir: $dataset->relativeSubdir(),
            maxRecords: $dataset->maxRecordsPerPart(),
            maxBytes: $dataset->maxBytesPerPart(),
            startPartIndex: $this->partIndex,
        );

        $limit = $this->datasetKey === 'articles' ? 100 : 500;
        $slice = $dataset->exportSlice($writer, $blobs, $this->afterId, $limit);
        $newParts = $writer->finish();

        $allParts = array_merge(
            $this->accumulatedParts,
            array_map(static fn (PartManifest $p): array => $p->toArray(), $newParts)
        );
        $totalDatasetCount = $this->accumulatedDatasetCount + $slice['count'];

        $run->update([
            'phase' => "export:{$this->datasetKey}",
            'current_dataset' => $this->datasetKey,
            'record_offset' => $slice['last_id'],
            'processed_records' => $run->processed_records + $slice['count'],
        ]);

        if ($slice['has_more']) {
            $nextPartIndex = $this->partIndex + count($newParts);
            ExportDatasetSliceJob::dispatch(
                runId: $this->runId,
                datasetKey: $this->datasetKey,
                datasetQueueIndex: $this->datasetQueueIndex,
                afterId: $slice['last_id'],
                partIndex: $nextPartIndex,
                accumulatedDatasetCount: $totalDatasetCount,
                accumulatedParts: $allParts,
            )->onQueue('client-transfer');

            return;
        }

        // Dataset finished! Save manifest in state file
        $stateFile = $stagingDir . DIRECTORY_SEPARATOR . 'export_state.json';
        $state = file_exists($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];
        if (! is_array($state)) {
            $state = [];
        }

        $datasetManifest = new DatasetManifest(
            key: $this->datasetKey,
            count: $totalDatasetCount,
            dependsOn: $dataset->dependencies(),
            parts: array_map(static fn (array $arr): PartManifest => PartManifest::fromArray($arr), $allParts),
        );

        $state['dataset_manifests'][$this->datasetKey] = $datasetManifest->toArray();
        $state['counts'][$this->datasetKey] = $totalDatasetCount;
        file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));

        $this->advanceToNextDataset($run);
    }

    private function advanceToNextDataset(ClientTransferRun $run): void
    {
        $queue = (array) ($run->metadata['datasets_queue'] ?? []);
        $nextIndex = $this->datasetQueueIndex + 1;

        if (isset($queue[$nextIndex])) {
            ExportDatasetSliceJob::dispatch(
                runId: $this->runId,
                datasetKey: (string) $queue[$nextIndex],
                datasetQueueIndex: $nextIndex,
                afterId: 0,
                partIndex: 1,
                accumulatedDatasetCount: 0,
                accumulatedParts: [],
            )->onQueue('client-transfer');
        } else {
            FinalizeSeoExportJob::dispatch($this->runId)->onQueue('client-transfer');
        }
    }

    public function failed(\Throwable $e): void
    {
        ClientTransferRun::query()->where('run_id', $this->runId)->first()?->markFailed($e->getMessage());
    }
}

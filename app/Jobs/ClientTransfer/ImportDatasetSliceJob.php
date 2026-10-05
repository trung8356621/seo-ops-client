<?php

declare(strict_types=1);

namespace App\Jobs\ClientTransfer;

use App\Models\ClientTransferRun;
use App\Services\ClientTransfer\DatasetRegistry;
use App\Services\ClientTransfer\Exceptions\FatalImportException;
use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Manifest\TransferManifest;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ImportDatasetSliceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $runId,
        public readonly string $datasetKey,
        public readonly int $datasetQueueIndex,
        public readonly int $partIndex = 0,
        public readonly int $byteOffset = 0,
        public readonly int $recordIndex = 0,
    ) {
        $this->onQueue('client-transfer');
    }

    public function handle(DatasetRegistry $registry): void
    {
        $run = ClientTransferRun::query()->where('run_id', $this->runId)->firstOrFail();
        $stagingDir = storage_path("app/client-transfer/staging_import_{$this->runId}");
        $manifestPath = $stagingDir.DIRECTORY_SEPARATOR.'manifest.json';

        if (! file_exists($manifestPath)) {
            throw new FatalImportException("Manifest missing in import staging dir [{$stagingDir}].");
        }

        $manifest = TransferManifest::fromJson((string) file_get_contents($manifestPath));
        $dataset = $registry->get($this->datasetKey);
        $datasetManifest = $manifest->datasets[$this->datasetKey] ?? null;

        if ($dataset === null || $datasetManifest === null || ! isset($datasetManifest->parts[$this->partIndex])) {
            $this->advanceToNextDataset($run);

            return;
        }

        $part = $datasetManifest->parts[$this->partIndex];
        $partPath = $stagingDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $part->file);
        if (! file_exists($partPath)) {
            throw new FatalImportException("Part file not found: [{$part->file}].");
        }

        $refMap = new ReferenceMap($this->runId);
        $importRun = new ImportRun($this->runId, $refMap);
        $blobs = new BlobManager($stagingDir.DIRECTORY_SEPARATOR.'content'.DIRECTORY_SEPARATOR.'blobs');

        $handle = fopen($partPath, 'rb');
        if ($handle === false) {
            throw new FatalImportException("Cannot open part file: [{$partPath}].");
        }

        // Seek directly to persisted byte offset (no line rescanning)
        if ($this->byteOffset > 0) {
            fseek($handle, $this->byteOffset);
        }

        $sliceLimit = $this->datasetKey === 'articles' ? 50 : 500;
        $recordsInSlice = 0;
        $currentRecordIndex = $this->recordIndex;
        $nextByteOffset = $this->byteOffset;
        $reachedEof = false;

        try {
            while ($recordsInSlice < $sliceLimit && ($line = fgets($handle)) !== false) {
                $lineRecordIndex = $currentRecordIndex;
                $currentRecordIndex++;
                $nextByteOffset = ftell($handle);

                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }

                $record = json_decode($trimmed, true);
                if (! is_array($record)) {
                    continue;
                }

                $recordRef = (string) ($record['ref'] ?? ($record['_ref'] ?? ''));

                // Idempotency: skip if already imported
                if ($recordRef !== '' && $refMap->hasImported($recordRef)) {
                    $recordsInSlice++;

                    continue;
                }

                $dataset->importRecord(
                    record: $record,
                    refMap: $refMap,
                    run: $importRun,
                    blobs: $blobs,
                    partFile: $part->file,
                    recordIndex: $lineRecordIndex,
                );

                $recordsInSlice++;
            }

            if (feof($handle)) {
                $reachedEof = true;
            }
        } finally {
            fclose($handle);
        }

        $stats = $importRun->getDatasetStats()[$this->datasetKey] ?? [];

        $run->update([
            'phase' => "import:{$this->datasetKey}",
            'current_dataset' => $this->datasetKey,
            'current_part' => $this->partIndex,
            'record_offset' => $nextByteOffset,
            'processed_records' => $run->processed_records + $recordsInSlice,
            'imported_count' => $run->imported_count + ($stats['imported'] ?? 0),
            'failed_count' => $run->failed_count + ($stats['failed'] ?? 0),
            'blocked_count' => $run->blocked_count + ($stats['blocked'] ?? 0),
            'warnings_count' => $run->warnings_count + ($stats['warnings'] ?? 0),
            'missing_refs_count' => $run->missing_refs_count + ($stats['missing_refs'] ?? 0),
        ]);

        if (! $reachedEof) {
            ImportDatasetSliceJob::dispatch(
                runId: $this->runId,
                datasetKey: $this->datasetKey,
                datasetQueueIndex: $this->datasetQueueIndex,
                partIndex: $this->partIndex,
                byteOffset: $nextByteOffset,
                recordIndex: $currentRecordIndex,
            )->onQueue('client-transfer');

            return;
        }

        // Current part completed: check if there is a next part in this dataset
        $nextPartIndex = $this->partIndex + 1;
        if (isset($datasetManifest->parts[$nextPartIndex])) {
            ImportDatasetSliceJob::dispatch(
                runId: $this->runId,
                datasetKey: $this->datasetKey,
                datasetQueueIndex: $this->datasetQueueIndex,
                partIndex: $nextPartIndex,
                byteOffset: 0,
                recordIndex: 0,
            )->onQueue('client-transfer');

            return;
        }

        $this->advanceToNextDataset($run);
    }

    private function advanceToNextDataset(ClientTransferRun $run): void
    {
        $queue = (array) ($run->metadata['datasets_queue'] ?? []);
        $nextIndex = $this->datasetQueueIndex + 1;

        if (isset($queue[$nextIndex])) {
            ImportDatasetSliceJob::dispatch(
                runId: $this->runId,
                datasetKey: (string) $queue[$nextIndex],
                datasetQueueIndex: $nextIndex,
                partIndex: 0,
                byteOffset: 0,
                recordIndex: 0,
            )->onQueue('client-transfer');
        } else {
            ResolveDeferredSliceJob::dispatch($this->runId, 0)->onQueue('client-transfer');
        }
    }

    public function failed(\Throwable $e): void
    {
        ClientTransferRun::query()->where('run_id', $this->runId)->first()?->markFailed($e->getMessage());
    }
}

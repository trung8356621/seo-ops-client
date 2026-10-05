<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Manifest\DatasetManifest;
use App\Services\ClientTransfer\Manifest\TransferManifest;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\NdjsonPartWriter;
use App\Services\ClientTransfer\Support\ZipArchiveManager;

final class QuarantinePackageBuilder
{
    public static function build(
        ImportRun $run,
        string $sourceExtractDir,
        string $outputZipPath,
        DatasetRegistry $registry,
    ): ?string {
        $quarantined = $run->getQuarantinedRecords();
        if (empty($quarantined)) {
            return null;
        }

        $stagingDir = storage_path("app/client-transfer/staging_quarantine_{$run->runId}");
        if (is_dir($stagingDir)) {
            self::deleteDir($stagingDir);
        }
        mkdir($stagingDir, 0755, true);

        try {
            // 1. Write import-errors.ndjson
            $errorsHandle = fopen($stagingDir . DIRECTORY_SEPARATOR . 'import-errors.ndjson', 'wb');
            if ($errorsHandle !== false) {
                foreach ($run->getLogs() as $log) {
                    fwrite($errorsHandle, json_encode($log->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                }
                fclose($errorsHandle);
            }

            // 2. Group records by dataset
            $byDataset = [];
            foreach ($quarantined as $item) {
                $byDataset[$item['dataset']][] = $item['record'];
            }

            $blobs = new BlobManager($stagingDir . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'blobs');
            $datasetManifests = [];

            foreach ($byDataset as $datasetKey => $records) {
                $dataset = $registry->get($datasetKey);
                if ($dataset === null) {
                    continue;
                }

                $datasetDir = $stagingDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dataset->relativeSubdir());
                $writer = new NdjsonPartWriter($datasetDir, $dataset->relativeSubdir(), $dataset->maxRecordsPerPart(), $dataset->maxBytesPerPart());

                foreach ($records as $record) {
                    // If record has body_blob, copy original blob to quarantine package
                    if (! empty($record['body_blob'])) {
                        $blobRel = (string) $record['body_blob'];
                        $srcBlob = $sourceExtractDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $blobRel);
                        $destBlob = $stagingDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $blobRel);
                        $destBlobDir = dirname($destBlob);
                        if (! is_dir($destBlobDir)) {
                            mkdir($destBlobDir, 0755, true);
                        }
                        if (file_exists($srcBlob) && ! file_exists($destBlob)) {
                            copy($srcBlob, $destBlob);
                        }
                    }

                    $writer->writeRecord($record);
                }

                $parts = $writer->finish();
                $datasetManifests[$datasetKey] = new DatasetManifest(
                    key: $datasetKey,
                    count: count($records),
                    dependsOn: $dataset->dependencies(),
                    parts: $parts,
                );
            }

            // 3. Write manifest.json
            $manifest = new TransferManifest(
                format: TransferManifest::FORMAT,
                formatVersion: TransferManifest::CURRENT_VERSION,
                exportedAt: date('c'),
                source: [
                    'app_version' => '1.0.0',
                    'database_driver' => config('database.default', 'mysql'),
                    'is_quarantine_retry' => true,
                    'original_run_id' => $run->runId,
                ],
                datasets: $datasetManifests,
            );

            file_put_contents($stagingDir . DIRECTORY_SEPARATOR . 'manifest.json', $manifest->toJson());

            // 4. Create ZIP
            ZipArchiveManager::create($stagingDir, $outputZipPath);

            return $outputZipPath;
        } finally {
            self::deleteDir($stagingDir);
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
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::deleteDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

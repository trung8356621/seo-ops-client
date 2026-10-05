<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Manifest\DatasetManifest;
use App\Services\ClientTransfer\Manifest\TransferManifest;
use App\Services\ClientTransfer\Support\NdjsonPartWriter;
use App\Services\ClientTransfer\Support\ReferenceMap;
use App\Services\ClientTransfer\Support\ZipArchiveManager;

final class QuarantinePackageBuilder
{
    public static function build(
        ImportRun $run,
        string $sourceExtractDir,
        string $outputZipPath,
        DatasetRegistry $registry,
    ): ?string {
        return self::buildFromRefMap($run->runId, $run->getReferenceMap(), $sourceExtractDir, $outputZipPath, $registry);
    }

    public static function buildFromRefMap(
        string $runId,
        ReferenceMap $refMap,
        string $sourceExtractDir,
        string $outputZipPath,
        DatasetRegistry $registry,
    ): ?string {
        if ($refMap->countFailures() === 0) {
            return null;
        }

        $stagingDir = storage_path("app/client-transfer/staging_quarantine_{$runId}");
        if (is_dir($stagingDir)) {
            self::deleteDir($stagingDir);
        }
        mkdir($stagingDir, 0755, true);

        try {
            // 1. Stream import-errors.ndjson in chunks
            $errorsHandle = fopen($stagingDir.DIRECTORY_SEPARATOR.'import-errors.ndjson', 'wb');
            if ($errorsHandle !== false) {
                $afterId = 0;
                do {
                    $logChunk = $refMap->getLogsChunk($afterId, 500);
                    foreach ($logChunk as $logRow) {
                        $logArray = [
                            'import_run_id' => $logRow['run_id'],
                            'dataset' => $logRow['dataset'],
                            'part' => $logRow['part'],
                            'record_ref' => $logRow['record_ref'],
                            'status' => $logRow['status'],
                            'error_type' => $logRow['error_type'],
                            'message' => $logRow['message'],
                            'source_file' => $logRow['source_file'],
                            'record_index' => $logRow['record_index'],
                            'created_at' => $logRow['created_at'],
                        ];
                        fwrite($errorsHandle, json_encode($logArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
                        $afterId = (int) $logRow['id'];
                    }
                } while (count($logChunk) === 500);

                fclose($errorsHandle);
            }

            // 2. Stream quarantined records from original extracted package by locator
            $failedParts = $refMap->getFailedParts();
            $byDataset = [];
            foreach ($failedParts as $fp) {
                $byDataset[$fp['dataset']][] = $fp['part'];
            }

            $datasetManifests = [];

            foreach ($byDataset as $datasetKey => $parts) {
                $dataset = $registry->get($datasetKey);
                if ($dataset === null) {
                    continue;
                }

                $datasetDir = $stagingDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $dataset->relativeSubdir());
                $writer = new NdjsonPartWriter($datasetDir, $dataset->relativeSubdir(), $dataset->maxRecordsPerPart(), $dataset->maxBytesPerPart());
                $datasetCount = 0;

                foreach ($parts as $partRel) {
                    $failedRefs = $refMap->getFailedRecordsForPart($datasetKey, $partRel);
                    if (empty($failedRefs)) {
                        continue;
                    }

                    $partPath = $sourceExtractDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $partRel);
                    if (! file_exists($partPath)) {
                        continue;
                    }

                    $handle = fopen($partPath, 'rb');
                    if ($handle === false) {
                        continue;
                    }

                    try {
                        while (($line = fgets($handle)) !== false) {
                            $line = trim($line);
                            if ($line === '') {
                                continue;
                            }

                            $record = json_decode($line, true);
                            if (! is_array($record)) {
                                continue;
                            }

                            $ref = (string) ($record['ref'] ?? ($record['_ref'] ?? ''));
                            if ($ref !== '' && isset($failedRefs[$ref])) {
                                // If record has body_blob, copy original blob to quarantine package
                                if (! empty($record['body_blob'])) {
                                    $blobRel = (string) $record['body_blob'];
                                    $srcBlob = $sourceExtractDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $blobRel);
                                    $destBlob = $stagingDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $blobRel);
                                    $destBlobDir = dirname($destBlob);
                                    if (! is_dir($destBlobDir)) {
                                        mkdir($destBlobDir, 0755, true);
                                    }
                                    if (file_exists($srcBlob) && ! file_exists($destBlob)) {
                                        copy($srcBlob, $destBlob);
                                    }
                                }

                                $writer->writeRecord($record);
                                $datasetCount++;
                            }
                        }
                    } finally {
                        fclose($handle);
                    }
                }

                $manifestParts = $writer->finish();
                if ($datasetCount > 0) {
                    $datasetManifests[$datasetKey] = new DatasetManifest(
                        key: $datasetKey,
                        count: $datasetCount,
                        dependsOn: $dataset->dependencies(),
                        parts: $manifestParts,
                    );
                }
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
                    'original_run_id' => $runId,
                ],
                datasets: $datasetManifests,
            );

            file_put_contents($stagingDir.DIRECTORY_SEPARATOR.'manifest.json', $manifest->toJson());

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
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                self::deleteDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

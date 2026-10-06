<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer;

use App\Services\ClientTransfer\Manifest\DatasetManifest;
use App\Services\ClientTransfer\Manifest\TransferManifest;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\NdjsonPartWriter;
use App\Services\ClientTransfer\Support\ZipArchiveManager;
use Illuminate\Support\Str;

final class ClientTransferExporter
{
    public function __construct(
        private readonly DatasetRegistry $registry = new DatasetRegistry,
    ) {}

    /**
     * @return array{
     *     destination_path: string,
     *     file_size: int,
     *     exported_at: string,
     *     counts: array<string, int>,
     *     manifest: TransferManifest
     * }
     */
    public function export(?string $destinationZipPath = null): array
    {
        $exportId = Str::random(12);
        if ($destinationZipPath === null) {
            $dir = storage_path('app/client-transfer/exports');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $destinationZipPath = $dir.DIRECTORY_SEPARATOR.'seo-export-'.date('Ymd-His').'-'.$exportId.'.zip';
        }

        $stagingDir = storage_path("app/client-transfer/staging_export_{$exportId}");
        if (is_dir($stagingDir)) {
            $this->deleteDir($stagingDir);
        }
        mkdir($stagingDir, 0755, true);

        $blobDir = $stagingDir.DIRECTORY_SEPARATOR.'content'.DIRECTORY_SEPARATOR.'blobs';
        $blobs = new BlobManager($blobDir);

        $datasetManifests = [];
        $counts = [];

        try {
            $datasets = $this->registry->sortedDatasets();

            foreach ($datasets as $dataset) {
                $datasetDir = $stagingDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $dataset->relativeSubdir());
                $writer = new NdjsonPartWriter(
                    directory: $datasetDir,
                    relativeSubdir: $dataset->relativeSubdir(),
                    maxRecords: $dataset->maxRecordsPerPart(),
                    maxBytes: $dataset->maxBytesPerPart(),
                );

                $count = $dataset->export($writer, $blobs);
                $parts = $writer->finish();

                $counts[$dataset->key()] = $count;
                $datasetManifests[$dataset->key()] = new DatasetManifest(
                    key: $dataset->key(),
                    count: $count,
                    dependsOn: $dataset->dependencies(),
                    parts: $parts,
                );
            }

            $mediaBinaryManager = new \App\Services\ClientTransfer\Support\MediaBinaryManager($stagingDir);
            $mediaBinaryManager->loadState();
            $mediaManifestData = $mediaBinaryManager->toManifestArray();

            $manifest = new TransferManifest(
                format: TransferManifest::FORMAT,
                formatVersion: TransferManifest::CURRENT_VERSION,
                exportedAt: date('c'),
                source: [
                    'app_version' => '1.0.0',
                    'database_driver' => config('database.default', 'mysql'),
                    'media_stats' => $mediaManifestData['stats'] ?? null,
                ],
                datasets: $datasetManifests,
                media: $mediaManifestData,
            );

            file_put_contents($stagingDir.DIRECTORY_SEPARATOR.'manifest.json', $manifest->toJson());

            ZipArchiveManager::create($stagingDir, $destinationZipPath);

            return [
                'destination_path' => $destinationZipPath,
                'file_size' => (int) filesize($destinationZipPath),
                'exported_at' => $manifest->exportedAt,
                'counts' => $counts,
                'manifest' => $manifest,
                'media_stats' => $mediaManifestData['stats'] ?? null,
            ];
        } finally {
            $this->deleteDir($stagingDir);
        }
    }

    private function deleteDir(string $dir): void
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
                $this->deleteDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

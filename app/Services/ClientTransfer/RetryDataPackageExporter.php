<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer;

use App\Services\ClientTransfer\Manifest\DatasetManifest;
use App\Services\ClientTransfer\Manifest\TransferManifest;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\NdjsonPartWriter;
use App\Services\ClientTransfer\Support\ZipArchiveManager;
use Illuminate\Support\Str;

final class RetryDataPackageExporter
{
    public function __construct(
        private readonly DatasetRegistry $registry = new DatasetRegistry,
        private readonly FailureRequestInspector $inspector = new FailureRequestInspector,
    ) {}

    /** @return array{destination_path: string, file_size: int, counts: array<string, int>, unresolvable_refs: list<string>, manifest: TransferManifest} */
    public function export(string $failureZipPath, ?string $destinationZipPath = null): array
    {
        $request = $this->inspector->inspect($failureZipPath);
        $id = Str::random(12);
        $destinationZipPath ??= storage_path('app/client-transfer/exports/seo-retry-data-'.date('Ymd-His').'-'.$id.'.zip');
        $staging = storage_path('app/client-transfer/staging_retry_export_'.$id);
        mkdir($staging, 0755, true);
        $blobs = new BlobManager($staging.DIRECTORY_SEPARATOR.'content'.DIRECTORY_SEPARATOR.'blobs');
        $manifests = [];
        $counts = [];
        $unresolved = [];

        try {
            foreach ($this->registry->sortedDatasets() as $dataset) {
                $refs = $request['refs'][$dataset->key()] ?? [];
                if ($refs === []) {
                    continue;
                }
                $writer = new NdjsonPartWriter(
                    $staging.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $dataset->relativeSubdir()),
                    $dataset->relativeSubdir(),
                    $dataset->maxRecordsPerPart(),
                    $dataset->maxBytesPerPart(),
                );
                $result = $dataset->exportSelectedRefs($refs, $writer, $blobs);
                $parts = $writer->finish();
                $counts[$dataset->key()] = $result['count'];
                array_push($unresolved, ...$result['unresolved']);
                if ($result['count'] > 0) {
                    $manifests[$dataset->key()] = new DatasetManifest($dataset->key(), $result['count'], $dataset->dependencies(), $parts);
                }
            }

            $manifest = new TransferManifest(
                TransferManifest::FORMAT,
                TransferManifest::CURRENT_VERSION,
                date('c'),
                [
                    'app_version' => '1.0.0',
                    'database_driver' => config('database.default', 'mysql'),
                    'service' => 'seo',
                    'is_retry_data' => true,
                    'package_semantics' => 'retry_data',
                    'original_import_run_id' => $request['original_import_run_id'],
                    'source_failure_package_sha256' => hash_file('sha256', $failureZipPath),
                    'unresolvable_refs' => $unresolved,
                ],
                $manifests,
            );
            file_put_contents($staging.DIRECTORY_SEPARATOR.'manifest.json', $manifest->toJson());
            ZipArchiveManager::create($staging, $destinationZipPath);

            return ['destination_path' => $destinationZipPath, 'file_size' => (int) filesize($destinationZipPath), 'counts' => $counts, 'unresolvable_refs' => $unresolved, 'manifest' => $manifest];
        } finally {
            FailureRequestInspectorCleanup::delete($staging);
        }
    }
}

final class FailureRequestInspectorCleanup
{
    public static function delete(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? self::delete($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

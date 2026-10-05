<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Support;

use App\Services\ClientTransfer\Exceptions\FatalImportException;
use App\Services\ClientTransfer\Manifest\TransferManifest;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

final class ZipArchiveManager
{
    /**
     * Compresses the staging directory into a ZIP archive.
     */
    public static function create(string $stagingDir, string $destinationZipPath): void
    {
        $zipDir = dirname($destinationZipPath);
        if (! is_dir($zipDir)) {
            mkdir($zipDir, 0755, true);
        }

        if (file_exists($destinationZipPath)) {
            @unlink($destinationZipPath);
        }

        $zip = new ZipArchive();
        $status = $zip->open($destinationZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($status !== true) {
            throw new \RuntimeException("Failed to create ZIP archive at [{$destinationZipPath}], code: {$status}");
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stagingDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $realStagingDir = realpath($stagingDir);
        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($realStagingDir) + 1);
            $relativePath = str_replace('\\', '/', $relativePath);

            $zip->addFile($filePath, $relativePath);
        }

        $zip->close();
    }

    /**
     * Extracts a ZIP archive to a destination directory and validates integrity.
     *
     * @return array{manifest: TransferManifest, extractDir: string}
     */
    public static function extractAndValidate(string $zipPath, string $destinationDir): array
    {
        if (! file_exists($zipPath)) {
            throw new FatalImportException("Transfer package not found at [{$zipPath}].");
        }

        if (! is_dir($destinationDir)) {
            mkdir($destinationDir, 0755, true);
        }

        $zip = new ZipArchive();
        $res = $zip->open($zipPath);
        if ($res !== true) {
            throw new FatalImportException("Failed to open transfer ZIP package, error code: {$res}");
        }

        $zip->extractTo($destinationDir);
        $zip->close();

        $manifestPath = $destinationDir . DIRECTORY_SEPARATOR . 'manifest.json';
        if (! file_exists($manifestPath)) {
            throw new FatalImportException("Package missing mandatory manifest.json.");
        }

        $manifestJson = file_get_contents($manifestPath);
        if ($manifestJson === false) {
            throw new FatalImportException("Unable to read manifest.json.");
        }

        $manifest = TransferManifest::fromJson($manifestJson);

        // Validate all part checksums
        foreach ($manifest->datasets as $datasetKey => $datasetManifest) {
            foreach ($datasetManifest->parts as $part) {
                $partPath = $destinationDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $part->file);
                if (! file_exists($partPath)) {
                    throw new FatalImportException("Missing package part file [{$part->file}] for dataset [{$datasetKey}].");
                }

                $actualSha256 = hash_file('sha256', $partPath);
                if ($actualSha256 !== $part->sha256) {
                    throw new FatalImportException(
                        "Checksum validation failed for [{$part->file}] in dataset [{$datasetKey}]: expected {$part->sha256}, got {$actualSha256}"
                    );
                }
            }
        }

        return [
            'manifest' => $manifest,
            'extractDir' => $destinationDir,
        ];
    }
}

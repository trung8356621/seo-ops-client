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
    public const MAX_ENTRIES = 50000;

    public const MAX_UNCOMPRESSED_BYTES = 5368709120; // 5 GB

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

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new FatalImportException("Transfer package exceeds maximum file entry limit (" . self::MAX_ENTRIES . ").");
            }

            $totalUncompressedBytes = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }

                $name = (string) ($stat['name'] ?? '');

                // Path traversal check
                if (str_contains($name, '../') || str_contains($name, '..\\')) {
                    throw new FatalImportException("Zip entry [{$name}] attempts illegal directory traversal.");
                }

                // Absolute path check
                if (
                    str_starts_with($name, '/')
                    || str_starts_with($name, '\\')
                    || preg_match('/^[a-zA-Z]:/', $name) === 1
                ) {
                    throw new FatalImportException("Zip entry [{$name}] contains an illegal absolute path.");
                }

                $totalUncompressedBytes += (int) ($stat['size'] ?? 0);
                if ($totalUncompressedBytes > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new FatalImportException("Transfer package uncompressed size exceeds maximum allowed limit.");
                }
            }

            $zip->extractTo($destinationDir);
        } finally {
            $zip->close();
        }

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

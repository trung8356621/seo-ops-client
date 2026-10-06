<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Support;

use Illuminate\Support\Facades\Storage;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Media\Services\SeoMediaPathAllocator;

final class MediaBinaryManager
{
    public const DEFAULT_MANAGED_ROOT = SeoMediaPathAllocator::BASE_DIR;

    public readonly string $filesDirectory;

    /** @var array{media_records: int, media_files: int, orphan_files: int, deduplicated_files: int, total_media_bytes: int, missing_media_files: int, unreadable_media_files: int} */
    private array $stats = [
        'media_records' => 0,
        'media_files' => 0,
        'orphan_files' => 0,
        'deduplicated_files' => 0,
        'total_media_bytes' => 0,
        'missing_media_files' => 0,
        'unreadable_media_files' => 0,
    ];

    /** @var list<array{code: string, ref: string, path: string, message: string}> */
    private array $warnings = [];

    /** @var array<string, bool> */
    private array $seenHashes = [];

    private bool $orphanExported = false;

    public function __construct(
        public readonly string $stagingDir,
        public readonly string $managedRelativeRoot = self::DEFAULT_MANAGED_ROOT,
        public readonly string $diskName = 'public',
    ) {
        $this->filesDirectory = $this->stagingDir.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'files';
        if (is_dir($this->stagingDir) && ! is_dir($this->filesDirectory)) {
            mkdir($this->filesDirectory, 0755, true);
        }
    }

    /**
     * Stores a physical binary for a database-backed media record if available.
     *
     * @return array{file_ref: string, size: int, mime_type: string, package_path: string, relative_path: string}|null
     */
    public function storeMediaRecordBinary(mixed $media): ?array
    {
        $this->stats['media_records']++;

        $ref = 'media:'.($media->id ?? 'unknown');
        $rawPath = (string) ($media->path ?? '');
        $rawUrl = (string) ($media->url ?? '');

        $relative = $this->normalizeCandidatePath($rawPath, $rawUrl);
        if ($relative === null) {
            return null;
        }

        // Security check: path traversal in candidate relative path
        if (str_contains($relative, '../') || str_contains($relative, '..\\')) {
            $this->recordWarning('MEDIA_PATH_TRAVERSAL', $ref, $relative, "Path traversal attempt detected in media [{$ref}]: [{$relative}].");

            return null;
        }

        $disk = Storage::disk($this->diskName);
        $absolutePath = $disk->path($relative);

        if (! file_exists($absolutePath)) {
            $this->stats['missing_media_files']++;
            $this->recordWarning('MEDIA_FILE_MISSING', $ref, $relative, "Media physical binary file missing for [{$ref}] at [{$relative}].");

            return null;
        }

        if (! $this->isContainedWithinManagedRoot($absolutePath)) {
            $this->recordWarning('MEDIA_FILE_CONTAINMENT_VIOLATION', $ref, $relative, "Media physical binary escapes approved media managed root for [{$ref}]: [{$relative}].");

            return null;
        }

        if ($this->isDerivedOrTempFile($absolutePath)) {
            return null;
        }

        $realFile = realpath($absolutePath);
        if ($realFile === false || ! is_readable($realFile)) {
            $this->stats['unreadable_media_files']++;
            $this->recordWarning('MEDIA_FILE_UNREADABLE', $ref, $relative, "Media binary file is not readable for [{$ref}] at [{$relative}].");

            return null;
        }

        $sha256 = @hash_file('sha256', $realFile);
        if ($sha256 === false || ! is_string($sha256)) {
            $this->stats['unreadable_media_files']++;
            $this->recordWarning('MEDIA_FILE_UNREADABLE', $ref, $relative, "Failed to hash media binary file for [{$ref}] at [{$relative}].");

            return null;
        }

        $fileSize = (int) filesize($realFile);
        $ext = $this->resolveExtension($realFile);
        $targetFilename = "{$sha256}.{$ext}";
        $targetFile = $this->filesDirectory.DIRECTORY_SEPARATOR.$targetFilename;

        if (file_exists($targetFile) || isset($this->seenHashes[$sha256])) {
            $this->stats['deduplicated_files']++;
            $this->seenHashes[$sha256] = true;
        } else {
            $copied = $this->streamCopyFile($realFile, $targetFile);
            if (! $copied) {
                $this->stats['unreadable_media_files']++;
                $this->recordWarning('MEDIA_FILE_COPY_FAILED', $ref, $relative, "Failed to stream copy binary for [{$ref}] to [{$targetFilename}].");

                return null;
            }
            $this->seenHashes[$sha256] = true;
            $this->stats['media_files']++;
            $this->stats['total_media_bytes'] += $fileSize;
        }

        return [
            'file_ref' => "sha256:{$sha256}",
            'size' => $fileSize,
            'mime_type' => $this->detectMimeType($realFile, $ext),
            'package_path' => "media/files/{$targetFilename}",
            'relative_path' => $relative,
        ];
    }

    public static function isApprovedManagedRoot(string $root): bool
    {
        $normalized = trim(str_replace('\\', '/', $root), '/');

        if (
            $normalized === ''
            || $normalized === '.'
            || $normalized === 'storage'
            || $normalized === 'storage/app'
            || $normalized === 'public'
            || $normalized === 'app'
            || $normalized === 'private'
            || str_starts_with($normalized, 'client-transfer')
        ) {
            return false;
        }

        return $normalized === 'uploads/seo_media' || str_starts_with($normalized, 'uploads/seo_media/');
    }

    /**
     * Inspects the Media-module managed root for orphan physical files (files not in DB)
     * and streams their binaries and orphan inventory to media/orphan_files.ndjson.
     */
    public function exportOrphanFiles(): int
    {
        if ($this->orphanExported) {
            return (int) ($this->stats['orphan_files'] ?? 0);
        }
        $this->orphanExported = true;

        if (! self::isApprovedManagedRoot($this->managedRelativeRoot)) {
            $this->recordWarning('INVALID_MANAGED_ROOT', 'orphan_scan', $this->managedRelativeRoot, "Orphan discovery cannot run on generic storage root: [{$this->managedRelativeRoot}].");

            return 0;
        }

        $disk = Storage::disk($this->diskName);
        $diskPath = $disk->path($this->managedRelativeRoot);
        if (! is_dir($diskPath)) {
            return 0;
        }

        $realRoot = realpath($diskPath);
        if ($realRoot === false) {
            return 0;
        }

        $dbPaths = [];
        try {
            $rawDbPaths = SeoMedia::query()->whereNotNull('path')->pluck('path')->all();
            foreach ($rawDbPaths as $p) {
                $norm = $this->normalizeCandidatePath((string) $p);
                if ($norm !== null) {
                    $dbPaths[$norm] = true;
                }
            }
        } catch (\Throwable) {
            // DB not available or table empty
        }

        $allFiles = $disk->allFiles($this->managedRelativeRoot);
        sort($allFiles);

        $orphanNdjsonPath = $this->stagingDir.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'orphan_files.ndjson';
        $orphanHandle = null;
        $orphanCount = 0;

        foreach ($allFiles as $fileRel) {
            $norm = $this->normalizeCandidatePath($fileRel);
            if ($norm === null) {
                continue;
            }

            if (isset($dbPaths[$norm])) {
                continue;
            }

            $absolutePath = $disk->path($norm);
            $realFile = realpath($absolutePath);

            if ($realFile === false || ! $this->isContainedWithinManagedRoot($realFile)) {
                $this->recordWarning('MEDIA_FILE_CONTAINMENT_VIOLATION', 'orphan:'.basename($norm), $norm, "Orphan file escapes approved root: [{$norm}].");

                continue;
            }

            if ($this->isDerivedOrTempFile($realFile)) {
                continue;
            }

            if (! is_readable($realFile)) {
                $this->stats['unreadable_media_files']++;
                $this->recordWarning('MEDIA_FILE_UNREADABLE', 'orphan:'.basename($norm), $norm, "Orphan file is not readable: [{$norm}].");

                continue;
            }

            $sha256 = @hash_file('sha256', $realFile);
            if ($sha256 === false || ! is_string($sha256)) {
                $this->stats['unreadable_media_files']++;
                $this->recordWarning('MEDIA_FILE_UNREADABLE', 'orphan:'.basename($norm), $norm, "Failed to hash orphan file: [{$norm}].");

                continue;
            }

            $fileSize = (int) filesize($realFile);
            $ext = $this->resolveExtension($realFile);
            $targetFilename = "{$sha256}.{$ext}";
            $targetFile = $this->filesDirectory.DIRECTORY_SEPARATOR.$targetFilename;

            if (file_exists($targetFile) || isset($this->seenHashes[$sha256])) {
                $this->stats['deduplicated_files']++;
                $this->seenHashes[$sha256] = true;
            } else {
                $copied = $this->streamCopyFile($realFile, $targetFile);
                if (! $copied) {
                    $this->stats['unreadable_media_files']++;
                    $this->recordWarning('MEDIA_FILE_COPY_FAILED', 'orphan:'.basename($norm), $norm, "Failed to copy orphan file [{$norm}].");

                    continue;
                }
                $this->seenHashes[$sha256] = true;
                $this->stats['media_files']++;
                $this->stats['total_media_bytes'] += $fileSize;
            }

            $orphanRecord = [
                'file_ref' => "sha256:{$sha256}",
                'managed_relative_path' => $norm,
                'filename' => basename($norm),
                'extension' => $ext,
                'mime_type' => $this->detectMimeType($realFile, $ext),
                'size' => $fileSize,
            ];

            if ($orphanHandle === null) {
                $orphanDir = dirname($orphanNdjsonPath);
                if (! is_dir($orphanDir)) {
                    mkdir($orphanDir, 0755, true);
                }
                $orphanHandle = fopen($orphanNdjsonPath, 'wb');
            }

            fwrite($orphanHandle, json_encode($orphanRecord, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $this->stats['orphan_files']++;
            $orphanCount++;
        }

        if ($orphanHandle !== null) {
            fclose($orphanHandle);
        }

        $this->saveState();

        return $orphanCount;
    }

    public function streamCopyFile(string $sourceFile, string $targetFile): bool
    {
        $src = @fopen($sourceFile, 'rb');
        if (! $src) {
            return false;
        }

        $dst = @fopen($targetFile, 'wb');
        if (! $dst) {
            fclose($src);

            return false;
        }

        $copied = stream_copy_to_stream($src, $dst);

        fclose($src);
        fclose($dst);

        return $copied !== false;
    }

    public function normalizeCandidatePath(string $rawPath, string $rawUrl = ''): ?string
    {
        $candidate = trim($rawPath);
        if ($candidate === '') {
            $url = trim($rawUrl);
            if (str_starts_with($url, '/storage/')) {
                $candidate = substr($url, strlen('/storage/'));
            } elseif (str_starts_with($url, 'storage/')) {
                $candidate = substr($url, strlen('storage/'));
            } else {
                return null;
            }
        }

        $candidate = str_replace('\\', '/', $candidate);
        $candidate = ltrim($candidate, '/');

        if (str_starts_with($candidate, 'storage/')) {
            $candidate = substr($candidate, strlen('storage/'));
        }

        // Normalize Windows drive letter path e.g. D:/.../uploads/seo_media/...
        if (preg_match('/^[a-zA-Z]:/', $candidate)) {
            $pos = strpos($candidate, $this->managedRelativeRoot);
            if ($pos !== false) {
                $candidate = substr($candidate, $pos);
            }
        }

        return $candidate !== '' ? $candidate : null;
    }

    public function isContainedWithinManagedRoot(string $absolutePath): bool
    {
        $realPath = realpath($absolutePath);
        if ($realPath === false) {
            return false;
        }

        $diskPath = Storage::disk($this->diskName)->path($this->managedRelativeRoot);
        if (! is_dir($diskPath)) {
            @mkdir($diskPath, 0755, true);
        }

        $realRoot = realpath($diskPath);
        if ($realRoot === false) {
            return false;
        }

        if ($realPath === $realRoot) {
            return true;
        }

        $prefix = rtrim($realRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($realPath, $prefix);
    }

    public function isDerivedOrTempFile(string $absolutePath): bool
    {
        $normalized = str_replace('\\', '/', $absolutePath);

        // Exclude ClientTransfer's own directories and transfer artifacts
        if (
            str_contains($normalized, 'client-transfer')
            || str_contains($normalized, 'staging_export_')
            || str_contains($normalized, 'staging_import_')
            || str_contains($normalized, 'staging_inspect_')
            || str_contains($normalized, 'staging_retry_')
            || str_contains($normalized, '/quarantine')
            || str_contains($normalized, '/exports')
        ) {
            return true;
        }

        $filename = basename($absolutePath);
        if (str_starts_with($filename, '.')) {
            return true;
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $tempExtensions = ['tmp', 'temp', 'part', 'crdownload', 'bak', 'lock', 'swp', 'zip'];
        if (in_array($ext, $tempExtensions, true)) {
            return true;
        }

        if ($filename === 'placeholder-loading.svg') {
            return true;
        }

        return false;
    }

    public function detectMimeType(string $realFile, string $ext): string
    {
        $mime = @mime_content_type($realFile);
        if (is_string($mime) && $mime !== '' && ! str_starts_with($mime, 'text/plain')) {
            return $mime;
        }

        return match ($ext) {
            'webp' => 'image/webp',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }

    public function resolveExtension(string $filePath): string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') {
            return 'jpg';
        }

        return $ext !== '' ? $ext : 'bin';
    }

    public function recordWarning(string $code, string $ref, string $path, string $message): void
    {
        $this->warnings[] = [
            'code' => $code,
            'ref' => $ref,
            'path' => $path,
            'message' => $message,
        ];
    }

    /**
     * @return array{media_records: int, media_files: int, orphan_files: int, deduplicated_files: int, total_media_bytes: int, missing_media_files: int, unreadable_media_files: int}
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * @return list<array{code: string, ref: string, path: string, message: string}>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function loadState(): void
    {
        $stateFile = $this->stagingDir.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'_state.json';
        if (file_exists($stateFile)) {
            $data = json_decode((string) file_get_contents($stateFile), true);
            if (is_array($data)) {
                $this->stats = array_merge($this->stats, (array) ($data['stats'] ?? []));
                $this->warnings = array_merge($this->warnings, (array) ($data['warnings'] ?? []));
                $this->seenHashes = array_fill_keys((array) ($data['seen_hashes'] ?? []), true);
                $this->orphanExported = (bool) ($data['orphan_exported'] ?? false);
            }
        }
    }

    public function saveState(): void
    {
        $mediaDir = $this->stagingDir.DIRECTORY_SEPARATOR.'media';
        if (! is_dir($mediaDir)) {
            mkdir($mediaDir, 0755, true);
        }

        $stateFile = $mediaDir.DIRECTORY_SEPARATOR.'_state.json';
        file_put_contents($stateFile, json_encode([
            'stats' => $this->stats,
            'warnings' => $this->warnings,
            'seen_hashes' => array_keys($this->seenHashes),
            'orphan_exported' => $this->orphanExported,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function writeWarningsFile(): void
    {
        if ($this->warnings === []) {
            return;
        }

        $warningsPath = $this->stagingDir.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'warnings.ndjson';
        $dir = dirname($warningsPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = fopen($warningsPath, 'wb');
        if ($handle !== false) {
            foreach ($this->warnings as $warning) {
                fwrite($handle, json_encode($warning, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            }
            fclose($handle);
        }
    }

    public function cleanupStateFile(): void
    {
        $stateFile = $this->stagingDir.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'_state.json';
        if (file_exists($stateFile)) {
            @unlink($stateFile);
        }
    }

    /**
     * @return array{stats: array<string, int>, warnings: list<array{code: string, ref: string, path: string, message: string}>}
     */
    public function toManifestArray(): array
    {
        $this->writeWarningsFile();
        $this->cleanupStateFile();

        return [
            'stats' => [
                'media_records' => (int) ($this->stats['media_records'] ?? 0),
                'media_files' => (int) ($this->stats['media_files'] ?? 0),
                'orphan_files' => (int) ($this->stats['orphan_files'] ?? 0),
                'deduplicated_files' => (int) ($this->stats['deduplicated_files'] ?? 0),
                'total_media_bytes' => (int) ($this->stats['total_media_bytes'] ?? 0),
                'missing_media_files' => (int) ($this->stats['missing_media_files'] ?? 0),
                'unreadable_media_files' => (int) ($this->stats['unreadable_media_files'] ?? 0),
            ],
            'warnings' => array_slice($this->warnings, 0, 50),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Support;

use App\Services\ClientTransfer\Logging\ImportRun;
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

    /** @var array<string, string> sha256 => managed relative path restored this run */
    private array $restoredHashes = [];

    /** @var array{media_binaries_restored: int, media_binaries_reused: int, orphan_files_restored: int, missing_binaries: int, checksum_failures: int, restored_bytes: int} */
    private array $importStats = [
        'media_binaries_restored' => 0,
        'media_binaries_reused' => 0,
        'orphan_files_restored' => 0,
        'missing_binaries' => 0,
        'checksum_failures' => 0,
        'restored_bytes' => 0,
    ];

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

    /**
     * @return array{ok: bool, code: ?string, message: ?string, relative_path: ?string, reused: bool, created: bool, bytes: int, sha256: ?string}
     */
    public function restoreFileRef(string $fileRef, string $preferredSlug, string $fallbackExtension, ReferenceMap $refMap): array
    {
        $hash = $this->parseFileRef($fileRef);
        if ($hash === null) {
            $this->importStats['missing_binaries']++;

            return $this->importFailure('MEDIA_BINARY_MISSING', "Invalid or missing file_ref [{$fileRef}].");
        }

        if (isset($this->restoredHashes[$hash])) {
            $this->importStats['media_binaries_reused']++;

            return $this->importSuccess($this->restoredHashes[$hash], $hash, reused: true, created: false, bytes: 0);
        }

        $packageFile = $this->resolvePackageBinaryPath($hash);
        if ($packageFile === null) {
            $this->importStats['missing_binaries']++;

            return $this->importFailure('MEDIA_BINARY_MISSING', "Package binary missing for file_ref [sha256:{$hash}].");
        }

        $ext = $this->resolveExtension($packageFile);
        if ($ext === 'bin' && $fallbackExtension !== '') {
            $ext = $this->resolveExtension('x.'.$fallbackExtension);
        }

        $existing = $this->findExistingManagedFileWithHash($hash, $preferredSlug, $ext);
        if ($existing !== null) {
            $this->restoredHashes[$hash] = $existing;
            $this->importStats['media_binaries_reused']++;
            $this->saveState();

            return $this->importSuccess($existing, $hash, reused: true, created: false, bytes: 0);
        }

        $allocated = (new SeoMediaPathAllocator)->allocate($preferredSlug, $ext);
        $relative = $allocated['relative_path'];
        $written = $this->streamCopyPackageToManagedPath($packageFile, $relative, $hash);
        if (! $written['ok']) {
            return $written;
        }

        $this->restoredHashes[$hash] = $relative;
        if ($written['created']) {
            $this->journalCreatedFile($refMap, $relative);
            $this->importStats['media_binaries_restored']++;
            $this->importStats['restored_bytes'] += $written['bytes'];
        } else {
            $this->importStats['media_binaries_reused']++;
        }
        $this->saveState();

        return $this->importSuccess($relative, $hash, reused: ! $written['created'], created: $written['created'], bytes: $written['bytes']);
    }

    /**
     * @param  array<string, mixed>  $orphan
     * @return array{ok: bool, code: ?string, message: ?string, relative_path: ?string, reused: bool, created: bool, bytes: int, sha256: ?string}
     */
    public function restoreOrphanRecord(array $orphan, ReferenceMap $refMap): array
    {
        $fileRef = (string) ($orphan['file_ref'] ?? '');
        $hash = $this->parseFileRef($fileRef);
        if ($hash === null) {
            $this->importStats['missing_binaries']++;

            return $this->importFailure('MEDIA_BINARY_MISSING', 'Orphan file_ref is missing or invalid.');
        }

        if (isset($this->restoredHashes[$hash])) {
            $this->importStats['media_binaries_reused']++;

            return $this->importSuccess($this->restoredHashes[$hash], $hash, reused: true, created: false, bytes: 0);
        }

        $packageFile = $this->resolvePackageBinaryPath($hash);
        if ($packageFile === null) {
            $this->importStats['missing_binaries']++;

            return $this->importFailure('MEDIA_BINARY_MISSING', "Package binary missing for orphan [sha256:{$hash}].");
        }

        $ext = (string) ($orphan['extension'] ?? $this->resolveExtension($packageFile));
        $filename = (string) ($orphan['filename'] ?? basename($packageFile));
        $preferredSlug = pathinfo($filename, PATHINFO_FILENAME);
        if ($preferredSlug === '') {
            $preferredSlug = 'orphan-'.$hash;
        }

        $rawManagedPath = (string) ($orphan['managed_relative_path'] ?? '');
        if ($rawManagedPath !== '' && (
            str_contains(str_replace('\\', '/', $rawManagedPath), '../')
            || str_starts_with($rawManagedPath, '/')
            || preg_match('/^[a-zA-Z]:/', str_replace('\\', '/', $rawManagedPath)) === 1
        )) {
            return $this->importFailure('MEDIA_PATH_TRAVERSAL', 'Orphan managed_relative_path is not a safe managed path.');
        }

        $candidate = $this->safeOrphanRelativePath($rawManagedPath);
        if ($candidate !== null) {
            $existingHash = $this->hashManagedRelativePath($candidate);
            if ($existingHash === $hash) {
                $this->restoredHashes[$hash] = $candidate;
                $this->importStats['media_binaries_reused']++;
                $this->saveState();

                return $this->importSuccess($candidate, $hash, reused: true, created: false, bytes: 0);
            }
            if ($existingHash === null) {
                $written = $this->streamCopyPackageToManagedPath($packageFile, $candidate, $hash);
                if (! $written['ok']) {
                    return $written;
                }
                $this->restoredHashes[$hash] = $candidate;
                if ($written['created']) {
                    $this->journalCreatedFile($refMap, $candidate);
                    $this->importStats['orphan_files_restored']++;
                    $this->importStats['restored_bytes'] += $written['bytes'];
                } else {
                    $this->importStats['media_binaries_reused']++;
                }
                $this->saveState();

                return $this->importSuccess($candidate, $hash, reused: ! $written['created'], created: $written['created'], bytes: $written['bytes']);
            }
        }

        $existing = $this->findExistingManagedFileWithHash($hash, $preferredSlug, $ext);
        if ($existing !== null) {
            $this->restoredHashes[$hash] = $existing;
            $this->importStats['media_binaries_reused']++;
            $this->saveState();

            return $this->importSuccess($existing, $hash, reused: true, created: false, bytes: 0);
        }

        $allocated = (new SeoMediaPathAllocator)->allocate($preferredSlug, $ext);
        $relative = $allocated['relative_path'];
        $written = $this->streamCopyPackageToManagedPath($packageFile, $relative, $hash);
        if (! $written['ok']) {
            return $written;
        }

        $this->restoredHashes[$hash] = $relative;
        if ($written['created']) {
            $this->journalCreatedFile($refMap, $relative);
            $this->importStats['orphan_files_restored']++;
            $this->importStats['restored_bytes'] += $written['bytes'];
        } else {
            $this->importStats['media_binaries_reused']++;
        }
        $this->saveState();

        return $this->importSuccess($relative, $hash, reused: ! $written['created'], created: $written['created'], bytes: $written['bytes']);
    }

    /**
     * @return array{processed: int, has_more: bool, next_offset: int}
     */
    public function importOrphanSlice(ReferenceMap $refMap, ImportRun $run, int $byteOffset = 0, int $limit = 1): array
    {
        $ndjsonPath = $this->stagingDir.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'orphan_files.ndjson';
        if (! is_file($ndjsonPath)) {
            return ['processed' => 0, 'has_more' => false, 'next_offset' => 0];
        }

        $handle = fopen($ndjsonPath, 'rb');
        if ($handle === false) {
            return ['processed' => 0, 'has_more' => false, 'next_offset' => $byteOffset];
        }

        if ($byteOffset > 0) {
            fseek($handle, $byteOffset);
        }

        $processed = 0;
        $nextOffset = $byteOffset;
        $reachedEof = false;

        try {
            while ($processed < $limit && ($line = fgets($handle)) !== false) {
                $nextOffset = (int) ftell($handle);
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }

                $record = json_decode($trimmed, true);
                if (! is_array($record)) {
                    continue;
                }

                $result = $this->restoreOrphanRecord($record, $refMap);
                if (! $result['ok']) {
                    $run->recordFailed(
                        'media',
                        'orphan:'.(string) ($record['filename'] ?? ($record['file_ref'] ?? 'unknown')),
                        (string) $result['code'],
                        (string) $result['message'],
                        'media/orphan_files.ndjson',
                        $processed,
                        rawRecord: $record,
                    );
                }
                $processed++;
            }

            $reachedEof = feof($handle);
        } finally {
            fclose($handle);
        }

        $this->saveState();

        return [
            'processed' => $processed,
            'has_more' => ! $reachedEof,
            'next_offset' => $nextOffset,
        ];
    }

    public function parseFileRef(string $fileRef): ?string
    {
        $fileRef = trim($fileRef);
        if (! str_starts_with($fileRef, 'sha256:')) {
            return null;
        }

        $hash = strtolower(substr($fileRef, 7));
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            return null;
        }

        return $hash;
    }

    public function resolvePackageBinaryPath(string $sha256): ?string
    {
        $filesDir = $this->filesDirectory;
        if (! is_dir($filesDir)) {
            return null;
        }

        $realDir = realpath($filesDir);
        if ($realDir === false) {
            return null;
        }

        $entries = scandir($filesDir);
        if ($entries === false) {
            return null;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (preg_match('/^'.preg_quote($sha256, '/').'\.[A-Za-z0-9]+$/', $entry) !== 1) {
                continue;
            }

            $absolute = $filesDir.DIRECTORY_SEPARATOR.$entry;
            $realFile = realpath($absolute);
            if ($realFile === false || is_link($absolute) || is_link($realFile)) {
                continue;
            }

            $prefix = rtrim($realDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            if ($realFile !== $realDir && ! str_starts_with($realFile, $prefix)) {
                continue;
            }

            if (! is_file($realFile) || ! is_readable($realFile)) {
                continue;
            }

            return $realFile;
        }

        return null;
    }

    /**
     * @return array{ok: bool, code: ?string, message: ?string, relative_path: ?string, reused: bool, created: bool, bytes: int, sha256: ?string}
     */
    public function streamCopyPackageToManagedPath(string $packageFile, string $relativePath, string $expectedHash): array
    {
        $relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));
        if (! $this->isSafeManagedRelativePath($relativePath)) {
            return $this->importFailure('MEDIA_PATH_TRAVERSAL', "Refusing to write media binary outside managed root [{$relativePath}].");
        }

        $disk = Storage::disk($this->diskName);
        $absolute = $disk->path($relativePath);
        $parent = dirname($absolute);
        if (! is_dir($parent)) {
            mkdir($parent, 0755, true);
        }

        if (is_file($absolute) && ! is_link($absolute)) {
            $existingHash = @hash_file('sha256', $absolute);
            if (is_string($existingHash) && $existingHash === $expectedHash && $this->isContainedWithinManagedRoot($absolute)) {
                return $this->importSuccess($relativePath, $expectedHash, reused: true, created: false, bytes: 0);
            }
        }

        $partPath = $absolute.'.ctimport.part';
        $hash = $this->streamCopyAndHash($packageFile, $partPath);
        if ($hash === null) {
            @unlink($partPath);

            return $this->importFailure('MEDIA_FILE_UNREADABLE', 'Failed to stream copy media binary from package.');
        }

        if ($hash !== $expectedHash) {
            @unlink($partPath);
            $this->importStats['checksum_failures']++;

            return $this->importFailure('MEDIA_CHECKSUM_MISMATCH', "SHA-256 mismatch for file_ref [sha256:{$expectedHash}].");
        }

        if (file_exists($absolute)) {
            @unlink($absolute);
        }

        if (! @rename($partPath, $absolute)) {
            @unlink($partPath);

            return $this->importFailure('MEDIA_FILE_COPY_FAILED', 'Failed to move restored media binary into managed storage.');
        }

        $realDest = realpath($absolute);
        if ($realDest === false || is_link($absolute) || ! $this->isContainedWithinManagedRoot($realDest)) {
            @unlink($absolute);

            return $this->importFailure('MEDIA_FILE_CONTAINMENT_VIOLATION', 'Restored media binary escaped the managed media root.');
        }

        return $this->importSuccess($relativePath, $expectedHash, reused: false, created: true, bytes: (int) filesize($absolute));
    }

    public function streamCopyAndHash(string $sourceFile, string $targetFile): ?string
    {
        $src = @fopen($sourceFile, 'rb');
        if ($src === false) {
            return null;
        }

        $dst = @fopen($targetFile, 'wb');
        if ($dst === false) {
            fclose($src);

            return null;
        }

        $ctx = hash_init('sha256');
        try {
            while (! feof($src)) {
                $chunk = fread($src, 1048576);
                if ($chunk === false) {
                    return null;
                }
                if ($chunk === '') {
                    break;
                }
                hash_update($ctx, $chunk);
                if (fwrite($dst, $chunk) === false) {
                    return null;
                }
            }
        } finally {
            fclose($src);
            fclose($dst);
        }

        return hash_final($ctx);
    }

    public function rollbackCreatedFile(string $relativePath): void
    {
        $relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));
        if (! $this->isSafeManagedRelativePath($relativePath)) {
            return;
        }

        $absolute = Storage::disk($this->diskName)->path($relativePath);
        $realFile = realpath($absolute);
        if ($realFile === false || is_link($absolute) || ! is_file($realFile)) {
            return;
        }

        if (! $this->isContainedWithinManagedRoot($realFile)) {
            return;
        }

        @unlink($realFile);
    }

    public function isSafeManagedRelativePath(string $relativePath): bool
    {
        $normalized = str_replace('\\', '/', ltrim(trim($relativePath), '/'));
        if ($normalized === '' || str_contains($normalized, '../') || str_contains($normalized, '..\\')) {
            return false;
        }

        if (str_starts_with($normalized, '/') || preg_match('/^[a-zA-Z]:/', $normalized) === 1) {
            return false;
        }

        return self::isApprovedManagedRoot($normalized) || self::isApprovedManagedRoot(dirname($normalized));
    }

    /**
     * @return array{media_binaries_restored: int, media_binaries_reused: int, orphan_files_restored: int, missing_binaries: int, checksum_failures: int, restored_bytes: int}
     */
    public function getImportStats(): array
    {
        return $this->importStats;
    }

    public function persistImportStatsToRun(string $runId): void
    {
        $run = \App\Models\ClientTransferRun::query()->where('run_id', $runId)->first();
        if ($run === null) {
            return;
        }

        $metadata = $run->metadata ?? [];
        $metadata['media_import'] = $this->importStats;
        $run->update(['metadata' => $metadata]);
    }

    public function deleteCreatedFilesFromJournal(ReferenceMap $refMap): void
    {
        do {
            $records = $refMap->getCreatedRecordsChunk('media', limit: 500);
            $fileIds = [];
            foreach ($records as $record) {
                if (($record['context']['kind'] ?? '') !== 'created_file') {
                    continue;
                }
                $this->rollbackCreatedFile((string) ($record['context']['relative_path'] ?? $record['target_key']));
                $fileIds[] = $record['id'];
            }
            $refMap->removeCreatedRecords($fileIds);
            if ($fileIds === []) {
                break;
            }
        } while (count($records) === 500);
    }

    /**
     * @return array{ok: bool, code: ?string, message: ?string, relative_path: ?string, reused: bool, created: bool, bytes: int, sha256: ?string}
     */
    private function importFailure(string $code, string $message): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'relative_path' => null,
            'reused' => false,
            'created' => false,
            'bytes' => 0,
            'sha256' => null,
        ];
    }

    /**
     * @return array{ok: bool, code: ?string, message: ?string, relative_path: ?string, reused: bool, created: bool, bytes: int, sha256: ?string}
     */
    private function importSuccess(string $relativePath, string $hash, bool $reused, bool $created, int $bytes): array
    {
        return [
            'ok' => true,
            'code' => null,
            'message' => null,
            'relative_path' => $relativePath,
            'reused' => $reused,
            'created' => $created,
            'bytes' => $bytes,
            'sha256' => $hash,
        ];
    }

    private function journalCreatedFile(ReferenceMap $refMap, string $relativePath): void
    {
        $refMap->trackCreated('media', 'bin:'.$relativePath, [
            'kind' => 'created_file',
            'relative_path' => $relativePath,
        ]);
    }

    private function findExistingManagedFileWithHash(string $hash, string $preferredSlug, string $extension): ?string
    {
        $slug = \Illuminate\Support\Str::slug($preferredSlug);
        if ($slug === '') {
            return null;
        }

        $candidate = SeoMediaPathAllocator::BASE_DIR.'/'.$slug.'.'.ltrim($extension, '.');
        $existingHash = $this->hashManagedRelativePath($candidate);
        if ($existingHash === $hash) {
            return $candidate;
        }

        return null;
    }

    private function hashManagedRelativePath(string $relativePath): ?string
    {
        if (! $this->isSafeManagedRelativePath($relativePath)) {
            return null;
        }

        $absolute = Storage::disk($this->diskName)->path($relativePath);
        if (! is_file($absolute) || is_link($absolute)) {
            return null;
        }

        $realFile = realpath($absolute);
        if ($realFile === false || ! $this->isContainedWithinManagedRoot($realFile)) {
            return null;
        }

        $hash = @hash_file('sha256', $realFile);

        return is_string($hash) ? $hash : null;
    }

    private function safeOrphanRelativePath(string $rawPath): ?string
    {
        $normalized = $this->normalizeCandidatePath($rawPath);
        if ($normalized === null || ! $this->isSafeManagedRelativePath($normalized)) {
            return null;
        }

        return $normalized;
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
                $this->importStats = array_merge($this->importStats, (array) ($data['import_stats'] ?? []));
                $this->warnings = array_merge($this->warnings, (array) ($data['warnings'] ?? []));
                $this->seenHashes = array_fill_keys((array) ($data['seen_hashes'] ?? []), true);
                $this->restoredHashes = [];
                foreach ((array) ($data['restored_hashes'] ?? []) as $hash => $path) {
                    if (is_string($hash) && is_string($path) && $path !== '') {
                        $this->restoredHashes[$hash] = $path;
                    }
                }
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
            'import_stats' => $this->importStats,
            'warnings' => $this->warnings,
            'seen_hashes' => array_keys($this->seenHashes),
            'restored_hashes' => $this->restoredHashes,
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

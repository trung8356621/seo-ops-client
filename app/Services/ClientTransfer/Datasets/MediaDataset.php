<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\MediaBinaryManager;
use App\Services\ClientTransfer\Support\NdjsonPartWriter;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\Media\Models\SeoMedia;

final class MediaDataset extends BaseDataset
{
    private ?MediaBinaryManager $mediaBinaryManager = null;

    public function key(): string
    {
        return 'media';
    }

    public function relativeSubdir(): string
    {
        return 'media/records';
    }

    public function dependencies(): array
    {
        return ['sites', 'articles'];
    }

    public function setMediaBinaryManager(?MediaBinaryManager $manager): self
    {
        $this->mediaBinaryManager = $manager;

        return $this;
    }

    public function getMediaBinaryManager(?BlobManager $blobs = null): MediaBinaryManager
    {
        if ($this->mediaBinaryManager !== null) {
            return $this->mediaBinaryManager;
        }

        $stagingDir = $blobs !== null ? dirname(dirname($blobs->blobDirectory)) : storage_path('app/client-transfer');

        return $this->mediaBinaryManager = new MediaBinaryManager($stagingDir);
    }

    protected function queryForExport(): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return SeoMedia::query();
    }

    public function exportSlice(NdjsonPartWriter $writer, BlobManager $blobs, int $afterId = 0, int $limit = 500): array
    {
        $slice = parent::exportSlice($writer, $blobs, $afterId, $limit);

        $mediaManager = $this->getMediaBinaryManager($blobs);
        $mediaManager->saveState();

        if (! $slice['has_more']) {
            $mediaManager->exportOrphanFiles();
        }

        return $slice;
    }

    public function export(NdjsonPartWriter $writer, BlobManager $blobs): int
    {
        $count = parent::export($writer, $blobs);

        $mediaManager = $this->getMediaBinaryManager($blobs);
        $mediaManager->exportOrphanFiles();

        return $count;
    }

    protected function mapRecordForExport(mixed $row, BlobManager $blobs): ?array
    {
        $mediaManager = $this->getMediaBinaryManager($blobs);
        $binaryInfo = $mediaManager->storeMediaRecordBinary($row);

        // Collect embedded article IDs from auxiliary meta
        $auxArticleIds = $row->getAttribute('article_id');
        $articleRefs = [];
        if (is_array($auxArticleIds)) {
            foreach ($auxArticleIds as $aId) {
                if (is_numeric($aId) && (int) $aId > 0) {
                    $articleRefs[] = 'article:'.(int) $aId;
                }
            }
        } elseif (is_numeric($auxArticleIds) && (int) $auxArticleIds > 0) {
            $articleRefs[] = 'article:'.(int) $auxArticleIds;
        }

        $rawPath = (string) ($row->path ?? '');
        $rawUrl = (string) ($row->url ?? '');
        $safePath = $mediaManager->normalizeCandidatePath($rawPath, $rawUrl) ?? $this->stripWindowsPath($rawPath);
        $safeUrl = $this->sanitizeUrl($rawUrl, $safePath);

        return [
            'ref' => 'media:'.$row->id,
            'site_ref' => $row->site_id ? ('site:'.$row->site_id) : null,
            'article_refs' => array_values(array_unique($articleRefs)),
            'filename' => (string) $row->filename,
            'slug' => (string) $row->slug,
            'path' => $safePath,
            'url' => $safeUrl,
            'source' => (string) ($row->source ?? 'upload'),
            'alt_text' => (string) ($row->alt_text ?? ''),
            'status' => (string) ($row->status ?? 'ready'),
            'wp_attachment_id' => $row->wp_attachment_id !== null ? (int) $row->wp_attachment_id : null,
            'wp_synced_at' => $row->wp_synced_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
            'file_ref' => $binaryInfo['file_ref'] ?? null,
            'original_filename' => (string) $row->filename,
            'mime_type' => $binaryInfo['mime_type'] ?? null,
            'size' => $binaryInfo['size'] ?? null,
        ];
    }

    private function stripWindowsPath(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        $normalized = ltrim($normalized, '/');
        if (preg_match('/^[a-zA-Z]:/', $normalized)) {
            $pos = strpos($normalized, 'uploads/seo_media');
            if ($pos !== false) {
                return substr($normalized, $pos);
            }

            return basename($normalized);
        }

        return $normalized;
    }

    private function sanitizeUrl(string $url, string $safePath): string
    {
        $url = trim($url);
        if ($url === '') {
            return $safePath !== '' ? '/storage/'.$safePath : '';
        }

        if (str_starts_with($url, 'file://') || preg_match('/^[a-zA-Z]:/', $url)) {
            return $safePath !== '' ? '/storage/'.$safePath : '';
        }

        if (str_starts_with($url, '/storage/')) {
            return $url;
        }

        if (preg_match('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?(/storage/.*)$#i', $url, $m)) {
            return $m[1];
        }

        return $url;
    }

    public function importOrphanFiles(ReferenceMap $refMap, ImportRun $run, BlobManager $blobs): void
    {
        $manager = $this->getMediaBinaryManager($blobs);
        $manager->loadState();
        $offset = 0;
        do {
            $slice = $manager->importOrphanSlice($refMap, $run, $offset, 50);
            $offset = $slice['next_offset'];
            $manager->persistImportStatsToRun($run->runId);
        } while ($slice['has_more']);
        $manager->saveState();
    }

    public function validateImportedBinaries(ReferenceMap $refMap, ImportRun $run): void
    {
        $manager = $this->getMediaBinaryManager();
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        $beforeId = 0;

        do {
            $chunk = $refMap->getCreatedRecordsChunk($this->key(), $beforeId, 500);
            foreach ($chunk as $record) {
                if (($record['context']['kind'] ?? '') === 'created_file') {
                    $relative = (string) ($record['context']['relative_path'] ?? '');
                    if ($relative === '' || ! $manager->isSafeManagedRelativePath($relative) || ! $disk->exists($relative)) {
                        $run->recordFailed('media', (string) $record['target_key'], 'MEDIA_VALIDATION_FAILED', 'Created media binary is missing after import.', null, 0);
                    }

                    continue;
                }

                $sha = (string) ($record['context']['sha256'] ?? '');
                $relative = (string) ($record['context']['relative_path'] ?? '');
                if ($sha === '' || $relative === '') {
                    continue;
                }

                if (! $manager->isSafeManagedRelativePath($relative)) {
                    $run->recordFailed('media', 'media:'.$record['target_key'], 'MEDIA_FILE_CONTAINMENT_VIOLATION', 'Imported media path escaped managed root.', null, 0);

                    continue;
                }

                $absolute = $disk->path($relative);
                if (! is_file($absolute)) {
                    $run->recordFailed('media', 'media:'.$record['target_key'], 'MEDIA_BINARY_MISSING', 'Imported media record has no accessible target binary.', null, 0);

                    continue;
                }

                $actual = hash_file('sha256', $absolute);
                if ($actual !== $sha) {
                    $run->recordFailed('media', 'media:'.$record['target_key'], 'MEDIA_CHECKSUM_MISMATCH', 'Restored media SHA-256 does not match file_ref.', null, 0);
                }

                $media = SeoMedia::query()->find($record['target_key']);
                if ($media !== null && str_replace('\\', '/', (string) $media->path) !== $relative) {
                    $run->recordFailed('media', 'media:'.$record['target_key'], 'MEDIA_VALIDATION_FAILED', 'Media DB path does not match restored target path.', null, 0);
                }
            }
            if ($chunk !== []) {
                $beforeId = (int) $chunk[array_key_last($chunk)]['id'];
            }
        } while (count($chunk) === 500);
    }

    public function importRecord(
        array $record,
        ReferenceMap $refMap,
        ImportRun $run,
        BlobManager $blobs,
        string $partFile,
        int $recordIndex,
    ): void {
        $ref = (string) ($record['ref'] ?? '');
        $siteRef = (string) ($record['site_ref'] ?? '');

        $siteId = $siteRef !== '' ? $refMap->get($siteRef) : null;
        $targetArticleIds = [];
        foreach ((array) ($record['article_refs'] ?? []) as $aRef) {
            $tId = $refMap->get((string) $aRef);
            if ($tId !== null && $tId > 0) {
                $targetArticleIds[] = $tId;
            }
        }

        $manager = $this->getMediaBinaryManager($blobs);
        $manager->loadState();

        $fileRef = trim((string) ($record['file_ref'] ?? ''));
        $binary = null;
        if ($fileRef !== '') {
            $preferredSlug = (string) ($record['slug'] ?? '');
            if ($preferredSlug === '') {
                $preferredSlug = pathinfo((string) ($record['filename'] ?? 'media'), PATHINFO_FILENAME);
            }
            $fallbackExt = pathinfo((string) ($record['filename'] ?? ($record['original_filename'] ?? '')), PATHINFO_EXTENSION);
            $binary = $manager->restoreFileRef($fileRef, $preferredSlug, (string) $fallbackExt, $refMap);
            $manager->persistImportStatsToRun($run->runId);
            if (! $binary['ok']) {
                $run->recordFailed('media', $ref, (string) $binary['code'], (string) $binary['message'], $partFile, $recordIndex, rawRecord: $record);

                return;
            }
        }

        try {
            $media = new SeoMedia;
            $media->site_id = $siteId;
            $media->filename = (string) ($record['filename'] ?? 'media');
            $media->slug = (string) ($record['slug'] ?? 'media');
            if ($binary !== null) {
                $media->path = (string) $binary['relative_path'];
                $media->url = '/storage/'.$binary['relative_path'];
            } else {
                $safePath = $this->portablePathWithoutAbsolute((string) ($record['path'] ?? ''));
                $media->path = $safePath;
                $media->url = $this->sanitizeUrl((string) ($record['url'] ?? ''), $safePath);
            }
            $media->source = (string) ($record['source'] ?? 'upload');
            $media->alt_text = (string) ($record['alt_text'] ?? '');
            $media->status = (string) ($record['status'] ?? 'ready');
            $media->wp_attachment_id = isset($record['wp_attachment_id']) ? (int) $record['wp_attachment_id'] : null;
            if (! empty($record['wp_synced_at'])) {
                $media->wp_synced_at = $record['wp_synced_at'];
            }
            if (! empty($record['created_at'])) {
                $media->created_at = $record['created_at'];
            }
            if (! empty($record['updated_at'])) {
                $media->updated_at = $record['updated_at'];
            }

            if (! empty($targetArticleIds)) {
                $media->setAttribute('article_id', $targetArticleIds);
            }

            $media->save();
            $context = [];
            if ($binary !== null) {
                $context['relative_path'] = $binary['relative_path'];
                $context['sha256'] = $binary['sha256'];
            }
            $refMap->trackCreated($this->key(), (int) $media->id, $context);

            $refMap->set($ref, 'media', (int) $media->id);
            $run->recordImported('media', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('media', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }

    public function rollbackImportedRecord(string $targetKey, array $context = []): void
    {
        if (($context['kind'] ?? '') === 'created_file') {
            $this->getMediaBinaryManager()->rollbackCreatedFile((string) ($context['relative_path'] ?? $targetKey));

            return;
        }

        parent::rollbackImportedRecord($targetKey, $context);
    }

    private function portablePathWithoutAbsolute(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        $normalized = ltrim($normalized, '/');
        if (preg_match('/^[a-zA-Z]:/', $normalized) === 1 || str_starts_with($normalized, 'file:')) {
            return '';
        }
        if (str_contains($normalized, '../')) {
            return '';
        }

        return $normalized;
    }
}

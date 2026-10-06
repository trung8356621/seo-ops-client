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

        try {
            $media = new SeoMedia;
            $media->site_id = $siteId;
            $media->filename = (string) ($record['filename'] ?? 'media');
            $media->slug = (string) ($record['slug'] ?? 'media');
            $media->path = (string) ($record['path'] ?? '');
            $media->url = (string) ($record['url'] ?? '');
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
            $refMap->trackCreated($this->key(), (int) $media->id);

            $refMap->set($ref, 'media', (int) $media->id);
            $run->recordImported('media', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('media', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }
}

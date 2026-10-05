<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\Media\Models\SeoMedia;

final class MediaDataset extends BaseDataset
{
    public function key(): string
    {
        return 'media';
    }

    public function relativeSubdir(): string
    {
        return 'media/media';
    }

    public function dependencies(): array
    {
        return ['sites', 'articles'];
    }

    protected function queryForExport(): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return SeoMedia::query();
    }

    protected function mapRecordForExport(mixed $row, BlobManager $blobs): ?array
    {
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

        $primaryArticleRef = $row->primary_article_id ? ('article:'.$row->primary_article_id) : null;

        return [
            'ref' => 'media:'.$row->id,
            'site_ref' => $row->site_id ? ('site:'.$row->site_id) : null,
            'primary_article_ref' => $primaryArticleRef,
            'article_refs' => array_values(array_unique($articleRefs)),
            'name' => (string) $row->name,
            'path' => (string) ($row->path ?? ''),
            'url' => (string) ($row->url ?? ''),
            'source' => (string) ($row->source ?? 'upload'),
            'mime_type' => (string) ($row->mime_type ?? ''),
            'file_size' => $row->file_size !== null ? (int) $row->file_size : null,
            'width' => $row->width !== null ? (int) $row->width : null,
            'height' => $row->height !== null ? (int) $row->height : null,
            'alt_text' => (string) ($row->alt_text ?? ''),
            'status' => (string) ($row->status ?? 'ready'),
            'wp_attachment_id' => $row->wp_attachment_id !== null ? (int) $row->wp_attachment_id : null,
            'wp_synced_at' => $row->wp_synced_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
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
        $primaryArticleId = ! empty($record['primary_article_ref']) ? $refMap->get((string) $record['primary_article_ref']) : null;

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
            $media->primary_article_id = $primaryArticleId;
            $media->name = (string) ($record['name'] ?? 'media');
            $media->path = (string) ($record['path'] ?? '');
            $media->url = (string) ($record['url'] ?? '');
            $media->source = (string) ($record['source'] ?? 'upload');
            $media->mime_type = (string) ($record['mime_type'] ?? '');
            $media->file_size = isset($record['file_size']) ? (int) $record['file_size'] : null;
            $media->width = isset($record['width']) ? (int) $record['width'] : null;
            $media->height = isset($record['height']) ? (int) $record['height'] : null;
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

            $refMap->set($ref, 'media', (int) $media->id);
            $run->recordImported('media', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('media', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }
}

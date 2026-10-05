<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\Content\Models\ArticleMeta;

final class ArticleMetaDataset extends BaseDataset
{
    public function key(): string
    {
        return 'article_meta';
    }

    public function relativeSubdir(): string
    {
        return 'content/article_meta';
    }

    public function dependencies(): array
    {
        return ['articles'];
    }

    protected function queryForExport(): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return ArticleMeta::query();
    }

    protected function mapRecordForExport(mixed $row, BlobManager $blobs): ?array
    {
        return [
            'ref' => 'article_meta:'.$row->id,
            'article_ref' => 'article:'.$row->article_id,
            'meta_key' => (string) $row->meta_key,
            'meta_value' => $row->meta_value,
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
        $articleRef = (string) ($record['article_ref'] ?? '');

        if ($run->isRootFailed($articleRef)) {
            $run->recordBlocked('article_meta', $ref, $articleRef, $partFile, $recordIndex, rawRecord: $record);

            return;
        }

        $targetArticleId = $refMap->get($articleRef);
        if ($targetArticleId === null) {
            $run->recordWarning('article_meta', $ref, "Missing parent article [{$articleRef}]", $partFile, $recordIndex, isMissingRef: true);

            return;
        }

        try {
            $meta = new ArticleMeta;
            $meta->article_id = $targetArticleId;
            $meta->meta_key = (string) ($record['meta_key'] ?? '');
            $meta->meta_value = $record['meta_value'] ?? null;
            if (! empty($record['created_at'])) {
                $meta->created_at = $record['created_at'];
            }
            if (! empty($record['updated_at'])) {
                $meta->updated_at = $record['updated_at'];
            }
            $meta->save();

            $run->recordImported('article_meta', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('article_meta', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }
}

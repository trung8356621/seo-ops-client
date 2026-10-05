<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\NdjsonPartWriter;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\Content\Models\SeoArticleHeading;

final class ArticleHeadingsDataset extends BaseDataset
{
    public function key(): string
    {
        return 'article_headings';
    }

    public function relativeSubdir(): string
    {
        return 'content/article_headings';
    }

    public function dependencies(): array
    {
        return ['articles'];
    }

    public function export(NdjsonPartWriter $writer, BlobManager $blobs): int
    {
        $count = 0;
        SeoArticleHeading::query()->orderBy('id')->chunkById(500, function ($rows) use ($writer, &$count): void {
            foreach ($rows as $row) {
                $record = [
                    'ref' => 'heading:' . $row->id,
                    'article_ref' => 'article:' . $row->article_id,
                    'parent_heading_ref' => $row->parent_id ? ('heading:' . $row->parent_id) : null,
                    'level' => (int) $row->level,
                    'text' => (string) $row->text,
                    'slug' => (string) ($row->slug ?? ''),
                    'sort_order' => (int) ($row->sort_order ?? 0),
                    'created_at' => $row->created_at?->toIso8601String(),
                    'updated_at' => $row->updated_at?->toIso8601String(),
                ];

                $writer->writeRecord($record);
                $count++;
            }
        });

        return $count;
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
            $run->recordBlocked('article_headings', $ref, $articleRef, $partFile, $recordIndex, rawRecord: $record);
            return;
        }

        $targetArticleId = $refMap->get($articleRef);
        if ($targetArticleId === null) {
            $run->recordWarning('article_headings', $ref, "Missing parent article [{$articleRef}]", $partFile, $recordIndex, isMissingRef: true);
            return;
        }

        try {
            $heading = new SeoArticleHeading();
            $heading->article_id = $targetArticleId;
            $heading->level = (int) ($record['level'] ?? 1);
            $heading->text = (string) ($record['text'] ?? '');
            $heading->slug = (string) ($record['slug'] ?? '');
            $heading->sort_order = (int) ($record['sort_order'] ?? 0);
            if (! empty($record['created_at'])) {
                $heading->created_at = $record['created_at'];
            }
            if (! empty($record['updated_at'])) {
                $heading->updated_at = $record['updated_at'];
            }
            $heading->save();

            $targetHeadingId = (int) $heading->id;
            $refMap->set($ref, 'heading', $targetHeadingId);

            if (! empty($record['parent_heading_ref'])) {
                $refMap->addDeferred('article_headings', $targetHeadingId, 'parent_id', (string) $record['parent_heading_ref']);
            }

            $run->recordImported('article_headings', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('article_headings', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }

    public function resolveDeferred(ReferenceMap $refMap, ImportRun $run): void
    {
        $deferred = $refMap->getDeferredReferences();
        foreach ($deferred as $item) {
            if ($item['entity_type'] !== 'article_headings' || $item['field_name'] !== 'parent_id') {
                continue;
            }

            $targetParentId = $refMap->get($item['target_ref']);
            if ($targetParentId !== null && $targetParentId > 0) {
                SeoArticleHeading::query()->where('id', $item['target_id'])->update(['parent_id' => $targetParentId]);
            } else {
                $run->recordWarning('article_headings', 'heading:' . $item['target_id'], "Unresolved deferred parent heading ref [{$item['target_ref']}]", isMissingRef: true);
            }
        }
    }
}

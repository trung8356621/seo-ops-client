<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\Content\Models\SeoFaq;

final class ArticleFaqsDataset extends BaseDataset
{
    public function key(): string
    {
        return 'article_faqs';
    }

    public function relativeSubdir(): string
    {
        return 'content/article_faqs';
    }

    public function dependencies(): array
    {
        return ['articles'];
    }

    protected function queryForExport(): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return SeoFaq::query();
    }

    protected function mapRecordForExport(mixed $row, BlobManager $blobs): ?array
    {
        return [
            'ref' => 'faq:'.$row->id,
            'article_ref' => 'article:'.$row->article_id,
            'question' => (string) $row->question,
            'answer' => (string) $row->answer,
            'sort_order' => (int) ($row->sort_order ?? 0),
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
            $run->recordBlocked('article_faqs', $ref, $articleRef, $partFile, $recordIndex, rawRecord: $record);

            return;
        }

        $targetArticleId = $refMap->get($articleRef);
        if ($targetArticleId === null) {
            $run->recordWarning('article_faqs', $ref, "Missing parent article [{$articleRef}]", $partFile, $recordIndex, isMissingRef: true);

            return;
        }

        try {
            $faq = new SeoFaq;
            $faq->article_id = $targetArticleId;
            $faq->question = (string) ($record['question'] ?? '');
            $faq->answer = (string) ($record['answer'] ?? '');
            $faq->sort_order = (int) ($record['sort_order'] ?? 0);
            if (! empty($record['created_at'])) {
                $faq->created_at = $record['created_at'];
            }
            if (! empty($record['updated_at'])) {
                $faq->updated_at = $record['updated_at'];
            }
            $faq->save();
            $refMap->trackCreated($this->key(), (int) $faq->id);

            $run->recordImported('article_faqs', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('article_faqs', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\Content\Models\SeoArticleReview;

final class ArticleReviewsDataset extends BaseDataset
{
    public function key(): string
    {
        return 'article_reviews';
    }

    public function relativeSubdir(): string
    {
        return 'content/article_reviews';
    }

    public function dependencies(): array
    {
        return ['articles', 'users'];
    }

    protected function queryForExport(): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return SeoArticleReview::query();
    }

    protected function mapRecordForExport(mixed $row, BlobManager $blobs): ?array
    {
        return [
            'ref' => 'review:'.$row->id,
            'article_ref' => 'article:'.$row->article_id,
            'reviewer_ref' => $row->reviewer_id ? ('user:'.$row->reviewer_id) : null,
            'action_type' => (string) ($row->action_type ?? ''),
            'from_status' => $row->from_status,
            'to_status' => (string) ($row->to_status ?? ''),
            'reviewer_role' => $row->reviewer_role,
            'note' => $row->note,
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
            $run->recordBlocked('article_reviews', $ref, $articleRef, $partFile, $recordIndex, rawRecord: $record);

            return;
        }

        $targetArticleId = $refMap->get($articleRef);
        if ($targetArticleId === null) {
            $run->recordWarning('article_reviews', $ref, "Missing parent article [{$articleRef}]", $partFile, $recordIndex, isMissingRef: true);

            return;
        }

        $reviewerId = null;
        if (! empty($record['reviewer_ref'])) {
            $reviewerId = $refMap->get((string) $record['reviewer_ref']);
        }

        try {
            $review = new SeoArticleReview;
            $review->article_id = $targetArticleId;
            $review->reviewer_id = $reviewerId;
            $review->action_type = (string) ($record['action_type'] ?? '');
            $review->from_status = $record['from_status'] ?? null;
            $review->to_status = (string) ($record['to_status'] ?? '');
            $review->reviewer_role = $record['reviewer_role'] ?? null;
            $review->note = $record['note'] ?? null;
            if (! empty($record['created_at'])) {
                $review->created_at = $record['created_at'];
            }
            if (! empty($record['updated_at'])) {
                $review->updated_at = $record['updated_at'];
            }
            $review->save();
            $refMap->trackCreated($this->key(), (int) $review->id);

            $run->recordImported('article_reviews', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('article_reviews', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\NdjsonPartWriter;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\Publishing\Models\PublishingArticleState;

final class PublishingArticleStatesDataset extends BaseDataset
{
    public function key(): string
    {
        return 'publishing_article_states';
    }

    public function relativeSubdir(): string
    {
        return 'publishing/publishing_article_states';
    }

    public function dependencies(): array
    {
        return ['articles'];
    }

    public function export(NdjsonPartWriter $writer, BlobManager $blobs): int
    {
        $count = 0;
        PublishingArticleState::query()->orderBy('id')->chunkById(500, function ($rows) use ($writer, &$count): void {
            foreach ($rows as $row) {
                $record = [
                    'ref' => 'pub_state:' . $row->id,
                    'article_ref' => 'article:' . $row->article_id,
                    'platform' => (string) ($row->platform ?? 'primary'),
                    'publication_status' => $row->publication_status,
                    'published_at' => $row->published_at?->toIso8601String(),
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
            $run->recordBlocked('publishing_article_states', $ref, $articleRef, $partFile, $recordIndex, rawRecord: $record);
            return;
        }

        $targetArticleId = $refMap->get($articleRef);
        if ($targetArticleId === null) {
            $run->recordWarning('publishing_article_states', $ref, "Missing parent article [{$articleRef}]", $partFile, $recordIndex, isMissingRef: true);
            return;
        }

        try {
            $state = new PublishingArticleState();
            $state->article_id = $targetArticleId;
            $state->platform = (string) ($record['platform'] ?? 'primary');
            $state->publication_status = $record['publication_status'] ?? null;
            if (! empty($record['published_at'])) {
                $state->published_at = $record['published_at'];
            }
            if (! empty($record['created_at'])) {
                $state->created_at = $record['created_at'];
            }
            if (! empty($record['updated_at'])) {
                $state->updated_at = $record['updated_at'];
            }
            $state->save();

            $run->recordImported('publishing_article_states', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('publishing_article_states', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }
}

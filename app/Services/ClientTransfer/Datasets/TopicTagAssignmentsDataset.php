<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTagAssignment;

final class TopicTagAssignmentsDataset extends BaseDataset
{
    public function key(): string
    {
        return 'topic_tag_assignments';
    }

    public function relativeSubdir(): string
    {
        return 'seo/topic_tag_assignments';
    }

    public function dependencies(): array
    {
        return ['topics', 'topic_tags'];
    }

    protected function queryForExport(): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return SeoTopicTagAssignment::query()->orderBy('topic_id')->orderBy('tag_id');
    }

    protected function fetchSliceRecords(int $afterId, int $limit): iterable
    {
        return $this->queryForExport()
            ->offset($afterId)
            ->limit($limit)
            ->get();
    }

    protected function getRecordIdForSlice(mixed $row, int $currentLastId): int
    {
        return $currentLastId + 1;
    }

    protected function mapRecordForExport(mixed $row, BlobManager $blobs): ?array
    {
        return [
            'topic_ref' => 'topic:'.$row->topic_id,
            'tag_ref' => 'topic_tag:'.$row->tag_id,
            'source' => (string) ($row->source ?? SeoTopicTagAssignment::SOURCE_MANUAL),
            'created_at' => $row->created_at?->toIso8601String(),
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
        $topicRef = (string) ($record['topic_ref'] ?? '');
        $tagRef = (string) ($record['tag_ref'] ?? '');

        $topicId = $refMap->get($topicRef);
        $tagId = $refMap->get($tagRef);

        if ($topicId === null || $tagId === null) {
            $run->recordWarning('topic_tag_assignments', "{$topicRef}_{$tagRef}", "Missing dependency topic [{$topicRef}] or tag [{$tagRef}]", $partFile, $recordIndex, isMissingRef: true);

            return;
        }

        try {
            $assignment = new SeoTopicTagAssignment;
            $assignment->topic_id = $topicId;
            $assignment->tag_id = $tagId;
            $assignment->source = (string) ($record['source'] ?? SeoTopicTagAssignment::SOURCE_MANUAL);
            if (! empty($record['created_at'])) {
                $assignment->created_at = $record['created_at'];
            }
            $assignment->save();

            $run->recordImported('topic_tag_assignments', "{$topicRef}_{$tagRef}", $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('topic_tag_assignments', "{$topicRef}_{$tagRef}", 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }
}

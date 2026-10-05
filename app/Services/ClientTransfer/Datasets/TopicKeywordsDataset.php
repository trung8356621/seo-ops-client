<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;

final class TopicKeywordsDataset extends BaseDataset
{
    public function key(): string
    {
        return 'topic_keywords';
    }

    public function relativeSubdir(): string
    {
        return 'seo/topic_keywords';
    }

    public function dependencies(): array
    {
        return ['topics', 'keywords', 'sites'];
    }

    protected function queryForExport(): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return SeoTopicKeyword::query();
    }

    protected function mapRecordForExport(mixed $row, BlobManager $blobs): ?array
    {
        return [
            'ref' => 'topic_keyword:'.$row->id,
            'site_ref' => 'site:'.$row->site_id,
            'topic_ref' => 'topic:'.$row->topic_id,
            'keyword_ref' => 'keyword:'.$row->keyword_id,
            'source' => (string) ($row->source ?? ''),
            'is_seed' => (bool) $row->is_seed,
            'is_locked' => (bool) $row->is_locked,
            'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
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
        $topicRef = (string) ($record['topic_ref'] ?? '');
        $kwRef = (string) ($record['keyword_ref'] ?? '');

        $siteId = $refMap->get($siteRef);
        $topicId = $refMap->get($topicRef);
        $kwId = $refMap->get($kwRef);

        if ($siteId === null || $topicId === null || $kwId === null) {
            $run->recordWarning('topic_keywords', $ref, "Missing dependency site [{$siteRef}], topic [{$topicRef}], or keyword [{$kwRef}]", $partFile, $recordIndex, isMissingRef: true);

            return;
        }

        try {
            $tk = new SeoTopicKeyword;
            $tk->site_id = $siteId;
            $tk->topic_id = $topicId;
            $tk->keyword_id = $kwId;
            $tk->source = (string) ($record['source'] ?? '');
            $tk->is_seed = (bool) ($record['is_seed'] ?? false);
            $tk->is_locked = (bool) ($record['is_locked'] ?? false);
            $tk->confidence = isset($record['confidence']) ? (float) $record['confidence'] : null;
            if (! empty($record['created_at'])) {
                $tk->created_at = $record['created_at'];
            }
            if (! empty($record['updated_at'])) {
                $tk->updated_at = $record['updated_at'];
            }
            $tk->save();

            $refMap->set($ref, 'topic_keyword', (int) $tk->id);
            $run->recordImported('topic_keywords', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('topic_keywords', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTag;

final class TopicTagsDataset extends BaseDataset
{
    public function key(): string
    {
        return 'topic_tags';
    }

    public function relativeSubdir(): string
    {
        return 'seo/topic_tags';
    }

    public function dependencies(): array
    {
        return ['sites'];
    }

    protected function queryForExport(): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return SeoTopicTag::query();
    }

    protected function mapRecordForExport(mixed $row, BlobManager $blobs): ?array
    {
        return [
            'ref' => 'topic_tag:'.$row->id,
            'site_ref' => 'site:'.$row->site_id,
            'name' => (string) $row->name,
            'slug' => (string) ($row->slug ?? ''),
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
        $ref = (string) ($record['ref'] ?? '');
        $siteRef = (string) ($record['site_ref'] ?? '');
        $name = trim((string) ($record['name'] ?? ''));

        if ($name === '') {
            $run->recordFailed('topic_tags', $ref, 'VALIDATION', 'Tag name is required.', $partFile, $recordIndex, rawRecord: $record);

            return;
        }

        $targetSiteId = $refMap->get($siteRef);
        if ($targetSiteId === null) {
            $run->recordWarning('topic_tags', $ref, "Missing dependency site [{$siteRef}]", $partFile, $recordIndex, isMissingRef: true);

            return;
        }

        try {
            $tag = new SeoTopicTag;
            $tag->site_id = $targetSiteId;
            $tag->name = $name;
            $tag->slug = (string) ($record['slug'] ?? '');
            if (! empty($record['created_at'])) {
                $tag->created_at = $record['created_at'];
            }
            $tag->save();

            $refMap->set($ref, 'topic_tag', (int) $tag->id);
            $run->recordImported('topic_tags', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('topic_tags', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }
}

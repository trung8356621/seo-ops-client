<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;

final class TopicsDataset extends BaseDataset
{
    public function key(): string
    {
        return 'topics';
    }

    public function relativeSubdir(): string
    {
        return 'seo/topics';
    }

    public function dependencies(): array
    {
        return ['sites'];
    }

    protected function queryForExport(): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return SeoTopic::query();
    }

    protected function mapRecordForExport(mixed $row, BlobManager $blobs): ?array
    {
        return [
            'ref' => 'topic:'.$row->id,
            'site_ref' => 'site:'.$row->site_id,
            'name' => (string) $row->name,
            'source' => (string) ($row->source ?? ''),
            'status' => (string) ($row->status ?? 'active'),
            'is_locked' => (bool) $row->is_locked,
            'mcp_excluded' => (bool) $row->mcp_excluded,
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
        $name = trim((string) ($record['name'] ?? ''));

        if ($name === '') {
            $run->recordFailed('topics', $ref, 'VALIDATION', 'Topic name is required.', $partFile, $recordIndex, rawRecord: $record);

            return;
        }

        $targetSiteId = $refMap->get($siteRef);
        if ($targetSiteId === null) {
            $run->recordWarning('topics', $ref, "Missing dependency site [{$siteRef}]", $partFile, $recordIndex, isMissingRef: true);

            return;
        }

        try {
            $topic = new SeoTopic;
            $topic->site_id = $targetSiteId;
            $topic->name = $name;
            $topic->source = (string) ($record['source'] ?? '');
            $topic->status = (string) ($record['status'] ?? 'active');
            $topic->is_locked = (bool) ($record['is_locked'] ?? false);
            $topic->mcp_excluded = (bool) ($record['mcp_excluded'] ?? false);
            if (! empty($record['created_at'])) {
                $topic->created_at = $record['created_at'];
            }
            if (! empty($record['updated_at'])) {
                $topic->updated_at = $record['updated_at'];
            }
            $topic->save();
            $refMap->trackCreated($this->key(), (int) $topic->id);

            $refMap->set($ref, 'topic', (int) $topic->id);
            $run->recordImported('topics', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('topics', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\NdjsonPartWriter;
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

    public function export(NdjsonPartWriter $writer, BlobManager $blobs): int
    {
        $count = 0;
        SeoTopic::query()->orderBy('id')->chunkById(500, function ($topics) use ($writer, &$count): void {
            foreach ($topics as $topic) {
                $record = [
                    'ref' => 'topic:' . $topic->id,
                    'site_ref' => 'site:' . $topic->site_id,
                    'name' => (string) $topic->name,
                    'source' => (string) ($topic->source ?? ''),
                    'status' => (string) ($topic->status ?? 'active'),
                    'is_locked' => (bool) $topic->is_locked,
                    'mcp_excluded' => (bool) $topic->mcp_excluded,
                    'created_at' => $topic->created_at?->toIso8601String(),
                    'updated_at' => $topic->updated_at?->toIso8601String(),
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
            $topic = new SeoTopic();
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

            $refMap->set($ref, 'topic', (int) $topic->id);
            $run->recordImported('topics', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('topics', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }
}

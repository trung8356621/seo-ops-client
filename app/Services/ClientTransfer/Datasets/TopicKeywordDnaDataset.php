<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\NdjsonPartWriter;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeywordDna;

final class TopicKeywordDnaDataset extends BaseDataset
{
    public function key(): string
    {
        return 'topic_keyword_dna';
    }

    public function relativeSubdir(): string
    {
        return 'seo/topic_keyword_dna';
    }

    public function dependencies(): array
    {
        return ['topics', 'keywords', 'sites'];
    }

    public function export(NdjsonPartWriter $writer, BlobManager $blobs): int
    {
        $count = 0;
        SeoTopicKeywordDna::query()->orderBy('id')->chunkById(500, function ($rows) use ($writer, &$count): void {
            foreach ($rows as $row) {
                $record = [
                    'ref' => 'dna:' . $row->id,
                    'site_ref' => 'site:' . $row->site_id,
                    'topic_ref' => 'topic:' . $row->topic_id,
                    'keyword_ref' => 'keyword:' . $row->keyword_id,
                    'value' => (string) $row->value,
                    'facet_type' => (string) ($row->facet_type ?? ''),
                    'placement' => (string) ($row->placement ?? ''),
                    'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
                    'source' => (string) ($row->source ?? ''),
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
        $siteRef = (string) ($record['site_ref'] ?? '');
        $topicRef = (string) ($record['topic_ref'] ?? '');
        $kwRef = (string) ($record['keyword_ref'] ?? '');

        $siteId = $refMap->get($siteRef);
        $topicId = $refMap->get($topicRef);
        $kwId = $refMap->get($kwRef);

        if ($siteId === null || $topicId === null || $kwId === null) {
            $run->recordWarning('topic_keyword_dna', $ref, "Missing dependency site [{$siteRef}], topic [{$topicRef}], or keyword [{$kwRef}]", $partFile, $recordIndex, isMissingRef: true);
            return;
        }

        try {
            $dna = new SeoTopicKeywordDna();
            $dna->site_id = $siteId;
            $dna->topic_id = $topicId;
            $dna->keyword_id = $kwId;
            $dna->value = (string) ($record['value'] ?? '');
            $dna->facet_type = (string) ($record['facet_type'] ?? '');
            $dna->placement = (string) ($record['placement'] ?? '');
            $dna->confidence = isset($record['confidence']) ? (float) $record['confidence'] : null;
            $dna->source = (string) ($record['source'] ?? '');
            if (! empty($record['created_at'])) {
                $dna->created_at = $record['created_at'];
            }
            if (! empty($record['updated_at'])) {
                $dna->updated_at = $record['updated_at'];
            }
            $dna->save();

            $refMap->set($ref, 'topic_keyword_dna', (int) $dna->id);
            $run->recordImported('topic_keyword_dna', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('topic_keyword_dna', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }
}

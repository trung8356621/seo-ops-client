<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSiteKeyword;

final class SiteKeywordsDataset extends BaseDataset
{
    public function key(): string
    {
        return 'site_keywords';
    }

    public function relativeSubdir(): string
    {
        return 'seo/site_keywords';
    }

    public function dependencies(): array
    {
        return ['sites', 'keywords'];
    }

    protected function queryForExport(): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return SeoSiteKeyword::query();
    }

    protected function mapRecordForExport(mixed $row, BlobManager $blobs): ?array
    {
        return [
            'ref' => 'site_keyword:'.$row->id,
            'site_ref' => 'site:'.$row->site_id,
            'keyword_ref' => 'keyword:'.$row->keyword_id,
            'phrase_kind' => (string) ($row->phrase_kind ?? ''),
            'seo_intent' => (string) ($row->seo_intent ?? ''),
            'is_seo_keyword' => (bool) $row->is_seo_keyword,
            'is_anchor_candidate' => (bool) $row->is_anchor_candidate,
            'is_ambiguous' => (bool) $row->is_ambiguous,
            'keyword_score' => $row->keyword_score !== null ? (float) $row->keyword_score : null,
            'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
            'review_state' => (string) ($row->review_state ?? ''),
            'source' => (string) ($row->source ?? ''),
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
        $kwRef = (string) ($record['keyword_ref'] ?? '');

        $targetSiteId = $refMap->get($siteRef);
        $targetKwId = $refMap->get($kwRef);

        if ($targetSiteId === null || $targetKwId === null) {
            $run->recordWarning('site_keywords', $ref, "Missing dependency site [{$siteRef}] or keyword [{$kwRef}]", $partFile, $recordIndex, isMissingRef: true);

            return;
        }

        try {
            $row = new SeoSiteKeyword;
            $row->site_id = $targetSiteId;
            $row->keyword_id = $targetKwId;
            $row->phrase_kind = (string) ($record['phrase_kind'] ?? '');
            $row->seo_intent = (string) ($record['seo_intent'] ?? '');
            $row->is_seo_keyword = (bool) ($record['is_seo_keyword'] ?? false);
            $row->is_anchor_candidate = (bool) ($record['is_anchor_candidate'] ?? false);
            $row->is_ambiguous = (bool) ($record['is_ambiguous'] ?? false);
            $row->keyword_score = isset($record['keyword_score']) ? (float) $record['keyword_score'] : null;
            $row->confidence = isset($record['confidence']) ? (float) $record['confidence'] : null;
            $row->review_state = (string) ($record['review_state'] ?? '');
            $row->source = (string) ($record['source'] ?? '');
            if (! empty($record['created_at'])) {
                $row->created_at = $record['created_at'];
            }
            if (! empty($record['updated_at'])) {
                $row->updated_at = $record['updated_at'];
            }
            $row->save();

            $refMap->set($ref, 'site_keyword', (int) $row->id);
            $run->recordImported('site_keywords', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('site_keywords', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\SiteSync\Models\SeoSiteManualLink;

final class ManualLinksDataset extends BaseDataset
{
    public function key(): string
    {
        return 'manual_links';
    }

    public function relativeSubdir(): string
    {
        return 'seo/manual_links';
    }

    public function dependencies(): array
    {
        return ['sites'];
    }

    protected function queryForExport(): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return SeoSiteManualLink::query();
    }

    protected function mapRecordForExport(mixed $row, BlobManager $blobs): ?array
    {
        return [
            'ref' => 'manual_link:'.$row->id,
            'site_ref' => 'site:'.$row->site_id,
            'keyword' => (string) $row->keyword,
            'url' => (string) $row->url,
            'url_hash' => (string) ($row->url_hash ?? md5((string) $row->url)),
            'is_locked' => (bool) $row->is_locked,
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
        $url = trim((string) ($record['url'] ?? ''));

        if ($url === '') {
            $run->recordFailed('manual_links', $ref, 'VALIDATION', 'URL is required.', $partFile, $recordIndex, rawRecord: $record);

            return;
        }

        $siteId = $refMap->get($siteRef);
        if ($siteId === null) {
            $run->recordWarning('manual_links', $ref, "Missing dependency site [{$siteRef}]", $partFile, $recordIndex, isMissingRef: true);

            return;
        }

        try {
            $link = new SeoSiteManualLink;
            $link->site_id = $siteId;
            $link->keyword = (string) ($record['keyword'] ?? '');
            $link->url = $url;
            $link->url_hash = (string) ($record['url_hash'] ?? md5($url));
            $link->is_locked = (bool) ($record['is_locked'] ?? false);
            if (! empty($record['created_at'])) {
                $link->created_at = $record['created_at'];
            }
            if (! empty($record['updated_at'])) {
                $link->updated_at = $record['updated_at'];
            }
            $link->save();
            $refMap->trackCreated($this->key(), (int) $link->id);

            $refMap->set($ref, 'manual_link', (int) $link->id);
            $run->recordImported('manual_links', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('manual_links', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\NdjsonPartWriter;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\SiteSync\Models\SeoSiteLinkExclusion;

final class LinkExclusionsDataset extends BaseDataset
{
    public function key(): string
    {
        return 'link_exclusions';
    }

    public function relativeSubdir(): string
    {
        return 'seo/link_exclusions';
    }

    public function dependencies(): array
    {
        return ['sites'];
    }

    public function export(NdjsonPartWriter $writer, BlobManager $blobs): int
    {
        $count = 0;
        SeoSiteLinkExclusion::query()->orderBy('id')->chunkById(500, function ($rows) use ($writer, &$count): void {
            foreach ($rows as $row) {
                $record = [
                    'ref' => 'link_exclusion:' . $row->id,
                    'site_ref' => 'site:' . $row->site_id,
                    'url' => (string) $row->url,
                    'url_hash' => (string) ($row->url_hash ?? md5((string) $row->url)),
                    'wordpress_id' => $row->wordpress_id !== null ? (int) $row->wordpress_id : null,
                    'reason' => (string) ($row->reason ?? ''),
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
        $url = trim((string) ($record['url'] ?? ''));

        if ($url === '') {
            $run->recordFailed('link_exclusions', $ref, 'VALIDATION', 'URL is required.', $partFile, $recordIndex, rawRecord: $record);
            return;
        }

        $siteId = $refMap->get($siteRef);
        if ($siteId === null) {
            $run->recordWarning('link_exclusions', $ref, "Missing dependency site [{$siteRef}]", $partFile, $recordIndex, isMissingRef: true);
            return;
        }

        try {
            $exclusion = new SeoSiteLinkExclusion();
            $exclusion->site_id = $siteId;
            $exclusion->url = $url;
            $exclusion->url_hash = (string) ($record['url_hash'] ?? md5($url));
            $exclusion->wordpress_id = isset($record['wordpress_id']) ? (int) $record['wordpress_id'] : null;
            $exclusion->reason = (string) ($record['reason'] ?? '');
            if (! empty($record['created_at'])) {
                $exclusion->created_at = $record['created_at'];
            }
            if (! empty($record['updated_at'])) {
                $exclusion->updated_at = $record['updated_at'];
            }
            $exclusion->save();

            $refMap->set($ref, 'link_exclusion', (int) $exclusion->id);
            $run->recordImported('link_exclusions', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('link_exclusions', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }
}

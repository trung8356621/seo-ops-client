<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Models\Site;
use App\Models\User;
use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\NdjsonPartWriter;
use App\Services\ClientTransfer\Support\ReferenceMap;

final class SitesDataset extends BaseDataset
{
    public function key(): string
    {
        return 'sites';
    }

    public function relativeSubdir(): string
    {
        return 'core/sites';
    }

    public function dependencies(): array
    {
        return ['users'];
    }

    public function export(NdjsonPartWriter $writer, BlobManager $blobs): int
    {
        $count = 0;
        Site::query()->orderBy('id')->chunkById(200, function ($sites) use ($writer, &$count): void {
            foreach ($sites as $site) {
                $record = [
                    'ref' => 'site:' . $site->id,
                    'user_ref' => $site->user_id ? ('user:' . $site->user_id) : null,
                    'domain' => (string) $site->domain,
                    'status' => (string) ($site->status ?? 'active'),
                    'ssl' => (bool) $site->ssl,
                    'created_at' => $site->created_at?->toIso8601String(),
                    'updated_at' => $site->updated_at?->toIso8601String(),
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
        $domain = strtolower(trim((string) ($record['domain'] ?? '')));

        if ($domain === '') {
            $run->recordFailed('sites', $ref, 'VALIDATION', 'Site domain is required.', $partFile, $recordIndex, rawRecord: $record);
            return;
        }

        try {
            $targetUserId = null;
            if (! empty($record['user_ref'])) {
                $targetUserId = $refMap->get((string) $record['user_ref']);
            }
            if ($targetUserId === null) {
                $targetUserId = User::query()->value('id');
            }

            $site = Site::query()->where('domain', $domain)->first();
            if ($site instanceof Site) {
                $refMap->set($ref, 'site', (int) $site->id);
                $run->recordImported('sites', $ref, $partFile, $recordIndex);
                return;
            }

            $newSite = new Site();
            $newSite->domain = $domain;
            $newSite->user_id = $targetUserId;
            $newSite->status = (string) ($record['status'] ?? 'active');
            $newSite->ssl = (bool) ($record['ssl'] ?? false);
            if (! empty($record['created_at'])) {
                $newSite->created_at = $record['created_at'];
            }
            if (! empty($record['updated_at'])) {
                $newSite->updated_at = $record['updated_at'];
            }
            $newSite->save();

            $refMap->set($ref, 'site', (int) $newSite->id);
            $run->recordImported('sites', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('sites', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }
}

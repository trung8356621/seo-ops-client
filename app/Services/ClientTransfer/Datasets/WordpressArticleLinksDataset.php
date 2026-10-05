<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;

final class WordpressArticleLinksDataset extends BaseDataset
{
    public function key(): string
    {
        return 'wordpress_article_links';
    }

    public function relativeSubdir(): string
    {
        return 'wordpress/wordpress_article_links';
    }

    public function dependencies(): array
    {
        return ['articles', 'sites'];
    }

    protected function queryForExport(): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return WordpressArticleLink::query();
    }

    protected function mapRecordForExport(mixed $row, BlobManager $blobs): ?array
    {
        return [
            'ref' => 'wp_article_link:'.$row->id,
            'article_ref' => 'article:'.$row->article_id,
            'site_ref' => $row->site_id ? ('site:'.$row->site_id) : null,
            'wp_post_id' => (int) $row->wp_post_id,
            'last_seen_sync_generation' => $row->last_seen_sync_generation !== null ? (int) $row->last_seen_sync_generation : null,
            'last_synced_at' => $row->last_synced_at?->toIso8601String(),
            'external_modified_at' => $row->external_modified_at?->toIso8601String(),
            'observed_modified_at' => $row->observed_modified_at?->toIso8601String(),
            'observed_at' => $row->observed_at?->toIso8601String(),
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
        $articleRef = (string) ($record['article_ref'] ?? '');

        if ($run->isRootFailed($articleRef)) {
            $run->recordBlocked('wordpress_article_links', $ref, $articleRef, $partFile, $recordIndex, rawRecord: $record);

            return;
        }

        $targetArticleId = $refMap->get($articleRef);
        if ($targetArticleId === null) {
            $run->recordWarning('wordpress_article_links', $ref, "Missing parent article [{$articleRef}]", $partFile, $recordIndex, isMissingRef: true);

            return;
        }

        $siteId = ! empty($record['site_ref']) ? $refMap->get((string) $record['site_ref']) : null;

        try {
            $link = new WordpressArticleLink;
            $link->article_id = $targetArticleId;
            $link->site_id = $siteId;
            $link->wp_post_id = (int) ($record['wp_post_id'] ?? 0);
            $link->last_seen_sync_generation = isset($record['last_seen_sync_generation']) ? (int) $record['last_seen_sync_generation'] : null;
            if (! empty($record['last_synced_at'])) {
                $link->last_synced_at = $record['last_synced_at'];
            }
            if (! empty($record['external_modified_at'])) {
                $link->external_modified_at = $record['external_modified_at'];
            }
            if (! empty($record['observed_modified_at'])) {
                $link->observed_modified_at = $record['observed_modified_at'];
            }
            if (! empty($record['observed_at'])) {
                $link->observed_at = $record['observed_at'];
            }
            if (! empty($record['created_at'])) {
                $link->created_at = $record['created_at'];
            }
            if (! empty($record['updated_at'])) {
                $link->updated_at = $record['updated_at'];
            }
            $link->save();

            $run->recordImported('wordpress_article_links', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('wordpress_article_links', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }
}

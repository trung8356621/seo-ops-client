<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\NdjsonPartWriter;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;

final class LinkMapsDataset extends BaseDataset
{
    public function key(): string
    {
        return 'link_maps';
    }

    public function relativeSubdir(): string
    {
        return 'seo/link_maps';
    }

    public function dependencies(): array
    {
        return ['keywords', 'articles', 'sites'];
    }

    public function export(NdjsonPartWriter $writer, BlobManager $blobs): int
    {
        $count = 0;
        SeoLinkMap::query()->orderBy('id')->chunkById(500, function ($rows) use ($writer, &$count): void {
            foreach ($rows as $row) {
                $record = [
                    'ref' => 'link_map:' . $row->id,
                    'keyword_ref' => $row->keyword_id ? ('keyword:' . $row->keyword_id) : null,
                    'source_article_ref' => $row->source_article_id ? ('article:' . $row->source_article_id) : null,
                    'target_article_ref' => $row->target_article_id ? ('article:' . $row->target_article_id) : null,
                    'target_site_ref' => $row->target_site_id ? ('site:' . $row->target_site_id) : null,
                    'target_external_url' => $row->target_external_url,
                    'anchor_text' => (string) $row->anchor_text,
                    'context_before' => $row->context_before,
                    'context_after' => $row->context_after,
                    'link_type' => $row->link_type instanceof \BackedEnum ? $row->link_type->value : (string) $row->link_type,
                    'status' => $row->status instanceof \BackedEnum ? $row->status->value : (string) $row->status,
                    'destination_kind' => $row->destination_kind instanceof \BackedEnum ? $row->destination_kind->value : (string) $row->destination_kind,
                    'is_semantic_eligible' => (bool) $row->is_semantic_eligible,
                    'last_http_status' => $row->last_http_status,
                    'last_audited_at' => $row->last_audited_at?->toIso8601String(),
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
        $kwRef = (string) ($record['keyword_ref'] ?? '');
        $srcArticleRef = (string) ($record['source_article_id'] ?? $record['source_article_ref'] ?? '');

        $kwId = $kwRef !== '' ? $refMap->get($kwRef) : null;
        $srcArticleId = $srcArticleRef !== '' ? $refMap->get($srcArticleRef) : null;
        $targetSiteId = ! empty($record['target_site_ref']) ? $refMap->get((string) $record['target_site_ref']) : null;

        try {
            $map = new SeoLinkMap();
            $map->keyword_id = $kwId;
            $map->source_article_id = $srcArticleId;
            $map->target_site_id = $targetSiteId;
            $map->target_external_url = $record['target_external_url'] ?? null;
            $map->anchor_text = (string) ($record['anchor_text'] ?? '');
            $map->context_before = $record['context_before'] ?? null;
            $map->context_after = $record['context_after'] ?? null;
            if (! empty($record['link_type'])) {
                $map->link_type = \Omnichannel\Addons\Seo\Enums\SeoLinkMapType::fromValue((string) $record['link_type']);
            }
            if (! empty($record['status'])) {
                $map->status = \Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus::tryFrom((string) $record['status']) ?? \Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus::Active;
            }
            if (! empty($record['destination_kind'])) {
                $map->destination_kind = \Omnichannel\Addons\Seo\Enums\SeoLinkMapDestinationKind::tryFrom((string) $record['destination_kind']) ?? \Omnichannel\Addons\Seo\Enums\SeoLinkMapDestinationKind::Content;
            }
            $map->is_semantic_eligible = (bool) ($record['is_semantic_eligible'] ?? false);
            $map->last_http_status = isset($record['last_http_status']) ? (int) $record['last_http_status'] : null;
            if (! empty($record['last_audited_at'])) {
                $map->last_audited_at = $record['last_audited_at'];
            }
            if (! empty($record['created_at'])) {
                $map->created_at = $record['created_at'];
            }
            if (! empty($record['updated_at'])) {
                $map->updated_at = $record['updated_at'];
            }
            $map->save();

            $mapId = (int) $map->id;
            $refMap->set($ref, 'link_map', $mapId);

            if (! empty($record['target_article_ref'])) {
                $refMap->addDeferred('link_maps', $mapId, 'target_article_id', (string) $record['target_article_ref']);
            }

            $run->recordImported('link_maps', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('link_maps', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }

    public function resolveDeferred(ReferenceMap $refMap, ImportRun $run): void
    {
        $deferred = $refMap->getDeferredReferences();
        foreach ($deferred as $item) {
            if ($item['entity_type'] !== 'link_maps' || $item['field_name'] !== 'target_article_id') {
                continue;
            }

            $targetArticleId = $refMap->get($item['target_ref']);
            if ($targetArticleId !== null && $targetArticleId > 0) {
                SeoLinkMap::query()->where('id', $item['target_id'])->update(['target_article_id' => $targetArticleId]);
            } else {
                $run->recordWarning('link_maps', 'link_map:' . $item['target_id'], "Unresolved deferred target article ref [{$item['target_ref']}]", isMissingRef: true);
            }
        }
    }
}

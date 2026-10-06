<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;

final class KeywordsDataset extends BaseDataset
{
    public function key(): string
    {
        return 'keywords';
    }

    public function relativeSubdir(): string
    {
        return 'seo/keywords';
    }

    public function dependencies(): array
    {
        return [];
    }

    public function maxRecordsPerPart(): int
    {
        return 20000;
    }

    protected function queryForExport(): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return Keyword::query()->with('metas');
    }

    protected function mapRecordForExport(mixed $row, BlobManager $blobs): ?array
    {
        $metas = $row->metas;

        // Group site-scoped metas
        $siteData = [];
        $globalMainArticleId = null;
        $tags = [];
        $qualityFlags = null;
        $seoHidden = false;
        $mcpExcluded = false;

        foreach ($metas as $meta) {
            $key = (string) $meta->meta_key;
            $val = (string) $meta->meta_value;

            if ($key === KeywordMetaKey::MainArticleId->value) {
                $globalMainArticleId = $val;
            } elseif ($key === KeywordMetaKey::Tags->value) {
                $decoded = json_decode($val, true);
                $tags = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $val)));
            } elseif ($key === KeywordMetaKey::QualityFlags->value) {
                $qualityFlags = $val;
            } elseif ($key === KeywordMetaKey::SeoHidden->value) {
                $seoHidden = (bool) $val;
            } elseif ($key === KeywordMetaKey::McpExcluded->value) {
                $mcpExcluded = (bool) $val;
            } elseif (KeywordMetaKey::isSiteScopedKey($key)) {
                $siteId = KeywordMetaKey::siteIdFromKey($key);
                if ($siteId !== null && $siteId > 0) {
                    $parts = explode('.', $key, 3);
                    $suffix = $parts[2] ?? '';
                    $siteData[$siteId][$suffix] = $val;
                }
            }
        }

        $siteStates = [];
        foreach ($siteData as $siteId => $data) {
            $siteStates[] = [
                'site_ref' => 'site:'.$siteId,
                'target_url' => $data['target_url'] ?? null,
                'search_volume' => isset($data['search_volume']) && is_numeric($data['search_volume']) ? (int) $data['search_volume'] : null,
                'difficulty' => isset($data['difficulty']) && is_numeric($data['difficulty']) ? (float) $data['difficulty'] : null,
                'rescrape_keep' => ! empty($data['rescrape_keep']),
                'link_policy_source' => $data['link_policy_source'] ?? null,
                'main_article_ref' => ! empty($data['main_article_id']) ? ('article:'.$data['main_article_id']) : null,
            ];
        }

        return [
            'ref' => 'keyword:'.$row->id,
            'phrase' => (string) $row->phrase,
            'type' => (string) ($row->type ?? Keyword::TYPE_NORMAL),
            'source' => (string) ($row->source ?? ''),
            'source_locked' => (bool) $row->source_locked,
            'review_status' => (string) ($row->review_status ?? ''),
            'review_note' => $row->review_note,
            'reviewed_at' => $row->reviewed_at?->toIso8601String(),
            'reviewed_by_ref' => $row->reviewed_by ? ('user:'.$row->reviewed_by) : null,
            'site_states' => $siteStates,
            'main_article_ref' => $globalMainArticleId ? ('article:'.$globalMainArticleId) : null,
            'tags' => array_values($tags),
            'quality_flags' => $qualityFlags,
            'seo_hidden' => $seoHidden,
            'mcp_excluded' => $mcpExcluded,
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
        $phrase = Keyword::preparePhraseForStorage(trim((string) ($record['phrase'] ?? '')));

        if ($phrase === '') {
            $run->recordFailed('keywords', $ref, 'VALIDATION', 'Keyword phrase is required.', $partFile, $recordIndex, rawRecord: $record);

            return;
        }

        try {
            // Canonical identity = normalized phrase + CI collation (global unique keywords.phrase).
            $existing = app(KeywordPersistenceService::class)->findByPhrase($phrase);
            $reused = $existing instanceof Keyword;

            if ($reused) {
                // Never overwrite target core state (review/source lock) — only map the ref.
                $targetId = (int) $existing->id;
            } else {
                $reviewedBy = null;
                if (! empty($record['reviewed_by_ref'])) {
                    $reviewedBy = $refMap->get((string) $record['reviewed_by_ref']);
                }

                $kw = new Keyword;
                $kw->phrase = $phrase;
                $kw->type = (string) ($record['type'] ?? Keyword::TYPE_NORMAL);
                $kw->source = (string) ($record['source'] ?? '');
                $kw->source_locked = (bool) ($record['source_locked'] ?? false);
                $kw->review_status = (string) ($record['review_status'] ?? '');
                $kw->review_note = $record['review_note'] ?? null;
                if (! empty($record['reviewed_at'])) {
                    $kw->reviewed_at = $record['reviewed_at'];
                }
                $kw->reviewed_by = $reviewedBy;
                $kw->save();
                $refMap->trackCreated($this->key(), (int) $kw->id);

                $targetId = (int) $kw->id;
            }

            $refMap->set($ref, 'keyword', $targetId);

            $repo = app(KeywordMetaRepository::class);
            // New keyword: write as-is. Reused keyword: fill only metas the target lacks.
            $put = static function (string $metaKey, string $value) use ($repo, $targetId, $reused): void {
                if ($reused && trim((string) $repo->get($targetId, $metaKey)) !== '') {
                    return;
                }
                $repo->set($targetId, $metaKey, $value);
            };
            $defer = static function (string $entityType, string $metaKey, string $articleRef) use ($repo, $refMap, $targetId, $reused): void {
                if ($reused && (
                    trim((string) $repo->get($targetId, $metaKey)) !== ''
                    || $refMap->hasDeferred($entityType, $targetId, $metaKey)
                )) {
                    return;
                }
                $refMap->addDeferred($entityType, $targetId, $metaKey, $articleRef);
            };

            // Reconstruct semantic site states
            $siteStates = (array) ($record['site_states'] ?? []);
            foreach ($siteStates as $state) {
                if (! is_array($state) || empty($state['site_ref'])) {
                    continue;
                }

                $targetSiteId = $refMap->get((string) $state['site_ref']);
                if ($targetSiteId === null || $targetSiteId <= 0) {
                    continue;
                }

                if (! empty($state['target_url'])) {
                    $put(KeywordMetaKey::siteTargetUrl($targetSiteId), (string) $state['target_url']);
                }
                if (isset($state['search_volume'])) {
                    $put(KeywordMetaKey::siteSearchVolume($targetSiteId), (string) (int) $state['search_volume']);
                }
                if (isset($state['difficulty'])) {
                    $put(KeywordMetaKey::siteDifficulty($targetSiteId), (string) $state['difficulty']);
                }
                if (! empty($state['rescrape_keep'])) {
                    $repo->set($targetId, KeywordMetaKey::siteRescrapeKeep($targetSiteId), '1');
                }
                if (! empty($state['link_policy_source'])) {
                    $put(KeywordMetaKey::siteLinkPolicySource($targetSiteId), (string) $state['link_policy_source']);
                }
                if (! empty($state['main_article_ref'])) {
                    $defer('keyword_meta_site_article', KeywordMetaKey::siteMainArticleId($targetSiteId), (string) $state['main_article_ref']);
                }
            }

            // Global metas
            if (! empty($record['main_article_ref'])) {
                $defer('keyword_meta_global_article', KeywordMetaKey::MainArticleId->value, (string) $record['main_article_ref']);
            }
            if (! empty($record['tags'])) {
                $tags = array_values((array) $record['tags']);
                if ($reused) {
                    $current = json_decode((string) $repo->get($targetId, KeywordMetaKey::Tags->value), true);
                    $tags = array_values(array_unique(array_merge(is_array($current) ? $current : [], $tags), SORT_REGULAR));
                }
                $repo->set($targetId, KeywordMetaKey::Tags->value, json_encode($tags, JSON_UNESCAPED_UNICODE));
            }
            if (! empty($record['quality_flags'])) {
                $put(KeywordMetaKey::QualityFlags->value, (string) $record['quality_flags']);
            }
            if (! empty($record['seo_hidden'])) {
                $repo->set($targetId, KeywordMetaKey::SeoHidden->value, '1');
            }
            if (! empty($record['mcp_excluded'])) {
                $repo->set($targetId, KeywordMetaKey::McpExcluded->value, '1');
            }

            if ($reused) {
                $run->recordWarning('keywords', $ref, "Reused existing canonical keyword [{$targetId}] for phrase [{$phrase}]; target review/source state preserved.", $partFile, $recordIndex);
            }
            $run->recordImported('keywords', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('keywords', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }

    public function resolveDeferred(ReferenceMap $refMap, ImportRun $run): void
    {
        $repo = app(KeywordMetaRepository::class);
        $deferred = $refMap->getDeferredReferences();

        foreach ($deferred as $item) {
            if ($item['entity_type'] !== 'keyword_meta_site_article' && $item['entity_type'] !== 'keyword_meta_global_article') {
                continue;
            }

            $targetArticleId = $refMap->get($item['target_ref']);
            if ($targetArticleId !== null && $targetArticleId > 0) {
                $repo->set((int) $item['target_id'], $item['field_name'], (string) $targetArticleId);
            } else {
                $run->recordWarning('keywords', 'keyword:'.$item['target_id'], "Unresolved deferred article ref [{$item['target_ref']}] for meta key [{$item['field_name']}]", isMissingRef: true);
            }
        }
    }
}

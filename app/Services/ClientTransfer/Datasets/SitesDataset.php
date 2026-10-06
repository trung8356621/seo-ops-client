<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Models\Site;
use App\Models\SiteMeta;
use App\Models\User;
use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

final class SitesDataset extends BaseDataset
{
    /**
     * Explicit allowlist of durable site and bridge configuration keys.
     * Runtime/cache/snapshot/heartbeat metadata must NEVER be added here.
     */
    public const PORTABLE_META_KEYS = [
        'seo_platform',
        'seo_publisher_key',
        'seo_domain_type',
        'seo_industry_context_key',
        'seo_primary_language',
        'seo_read_token',
        'seo_migration_token',
        'seo_sync_callback_secret',
        'wp_home_url',
    ];

    /**
     * Bridge identity tokens that require exact matching and masking in logs.
     */
    public const BRIDGE_TOKEN_KEYS = [
        'seo_read_token',
        'seo_migration_token',
        'seo_sync_callback_secret',
    ];

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

    protected function queryForExport(): Builder|Relation
    {
        return Site::query()->with(['metas' => function ($query): void {
            $query->whereIn('meta_key', self::PORTABLE_META_KEYS);
        }]);
    }

    protected function mapRecordForExport(mixed $row, BlobManager $blobs): ?array
    {
        $portableMeta = [];
        if ($row instanceof Site) {
            if (! $row->relationLoaded('metas')) {
                $row->load(['metas' => function ($query): void {
                    $query->whereIn('meta_key', self::PORTABLE_META_KEYS);
                }]);
            }

            foreach ($row->metas as $meta) {
                $key = (string) $meta->meta_key;
                if (in_array($key, self::PORTABLE_META_KEYS, true)) {
                    $portableMeta[$key] = $meta->meta_value !== null ? (string) $meta->meta_value : null;
                }
            }
        }

        ksort($portableMeta);

        return [
            'ref' => 'site:'.$row->id,
            'user_ref' => $row->user_id ? ('user:'.$row->user_id) : null,
            'domain' => (string) $row->domain,
            'status' => (string) ($row->status ?? 'active'),
            'ssl' => (bool) $row->ssl,
            'portable_meta' => $portableMeta,
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
        $domain = strtolower(trim((string) ($record['domain'] ?? '')));

        if ($domain === '') {
            $run->recordFailed('sites', $ref, 'VALIDATION', 'Site domain is required.', $partFile, $recordIndex, rawRecord: $this->sanitizeRecordForLogging($record));

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
                $targetSite = $site;
            } else {
                $targetSite = new Site;
                $targetSite->domain = $domain;
                $targetSite->user_id = $targetUserId;
                $targetSite->status = (string) ($record['status'] ?? 'active');
                $targetSite->ssl = (bool) ($record['ssl'] ?? false);
                if (! empty($record['created_at'])) {
                    $targetSite->created_at = $record['created_at'];
                }
                if (! empty($record['updated_at'])) {
                    $targetSite->updated_at = $record['updated_at'];
                }
                $targetSite->save();
                $refMap->trackCreated($this->key(), (int) $targetSite->id);
            }

            $portableMeta = is_array($record['portable_meta'] ?? null) ? $record['portable_meta'] : [];
            $this->restorePortableMeta($targetSite, $portableMeta);

            $validationErrors = $this->validateImportedPortableMeta($targetSite, $ref, $record);
            if ($validationErrors !== []) {
                foreach ($validationErrors as $errorMessage) {
                    $run->recordFailed('sites', $ref, 'VALIDATION', $errorMessage, $partFile, $recordIndex, rawRecord: $this->sanitizeRecordForLogging($record));
                }

                return;
            }

            $refMap->set($ref, 'site', (int) $targetSite->id);
            $run->recordImported('sites', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (isset($record['portable_meta']) && is_array($record['portable_meta'])) {
                foreach (self::BRIDGE_TOKEN_KEYS as $tokenKey) {
                    $val = (string) ($record['portable_meta'][$tokenKey] ?? '');
                    if ($val !== '') {
                        $msg = str_replace($val, '[REDACTED]', $msg);
                    }
                }
            }
            $run->recordFailed('sites', $ref, 'DB_ERROR', $msg, $partFile, $recordIndex, rawRecord: $this->sanitizeRecordForLogging($record));
        }
    }

    public function rollbackImportedRecord(string $targetKey, array $context = []): void
    {
        SiteMeta::query()->where('site_id', $targetKey)->delete();
        parent::rollbackImportedRecord($targetKey, $context);
    }

    /**
     * Restores allowlisted meta keys using Site / SiteMeta domain pattern.
     * Existing target site receives updates, duplicate rows are cleaned up,
     * and absent source keys do not invent or generate values.
     *
     * @param  array<string, mixed>  $portableMeta
     */
    protected function restorePortableMeta(Site $site, array $portableMeta): void
    {
        foreach (self::PORTABLE_META_KEYS as $key) {
            if (! array_key_exists($key, $portableMeta)) {
                continue;
            }

            $val = $portableMeta[$key];
            $valueToStore = $val !== null ? (string) $val : null;

            $existingMetas = $site->metas()->where('meta_key', $key)->get();
            if ($existingMetas->count() > 1) {
                $first = $existingMetas->first();
                $existingMetas->slice(1)->each->delete();
                $first->update(['meta_value' => $valueToStore]);
            } elseif ($existingMetas->count() === 1) {
                $existingMetas->first()->update(['meta_value' => $valueToStore]);
            } else {
                $site->metas()->create([
                    'meta_key' => $key,
                    'meta_value' => $valueToStore,
                ]);
            }
        }

        $site->unsetRelation('metas');
    }

    /**
     * Post-import semantic validation for WordPress sites with tokens.
     * Never leaks raw token values in output messages.
     *
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    public function validateImportedPortableMeta(Site $targetSite, string $ref, array $record): array
    {
        $portableMeta = is_array($record['portable_meta'] ?? null) ? $record['portable_meta'] : [];
        $platform = (string) ($portableMeta['seo_platform'] ?? '');

        $hasTokens = (! empty($portableMeta['seo_read_token']))
            || (! empty($portableMeta['seo_migration_token']))
            || (! empty($portableMeta['seo_sync_callback_secret']));

        $isWordPress = $platform === 'wordpress' || $hasTokens;
        if (! $isWordPress) {
            return [];
        }

        $tokensToCheck = [
            'seo_read_token',
            'seo_migration_token',
        ];

        if (array_key_exists('seo_sync_callback_secret', $portableMeta) && (string) $portableMeta['seo_sync_callback_secret'] !== '') {
            $tokensToCheck[] = 'seo_sync_callback_secret';
        }

        $errors = [];
        foreach ($tokensToCheck as $metaKey) {
            $expected = isset($portableMeta[$metaKey]) ? (string) $portableMeta[$metaKey] : '';
            if ($expected === '') {
                continue;
            }

            $actual = $targetSite->metas()->where('meta_key', $metaKey)->value('meta_value');
            if ($actual === null) {
                $errors[] = "Site ref [{$ref}] meta key [{$metaKey}] missing";
            } elseif ((string) $actual !== $expected) {
                $errors[] = "Site ref [{$ref}] meta key [{$metaKey}] mismatch";
            }
        }

        return $errors;
    }

    /**
     * Redacts bridge identity tokens before logging raw record payloads.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function sanitizeRecordForLogging(array $record): array
    {
        $sanitized = $record;
        if (isset($sanitized['portable_meta']) && is_array($sanitized['portable_meta'])) {
            foreach (self::BRIDGE_TOKEN_KEYS as $tokenKey) {
                if (isset($sanitized['portable_meta'][$tokenKey])) {
                    $sanitized['portable_meta'][$tokenKey] = '[REDACTED]';
                }
            }
        }

        return $sanitized;
    }
}

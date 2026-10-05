<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Models\Service;
use App\Models\SiteService;
use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

final class SiteServicesDataset extends BaseDataset
{
    public function key(): string
    {
        return 'site_services';
    }

    public function relativeSubdir(): string
    {
        return 'core/site_services';
    }

    public function dependencies(): array
    {
        return ['sites', 'users'];
    }

    protected function queryForExport(): Builder|Relation
    {
        return SiteService::query()->with('service');
    }

    protected function mapRecordForExport(mixed $row, BlobManager $blobs): ?array
    {
        $serviceSlug = (string) ($row->service?->slug ?? '');
        if ($serviceSlug === '') {
            return null;
        }

        $sanitizedSettings = $this->sanitizeSettings((array) ($row->settings ?? []));

        return [
            'ref' => 'site_service:'.$row->id,
            'site_ref' => $row->site_id ? ('site:'.$row->site_id) : null,
            'user_ref' => $row->user_id ? ('user:'.$row->user_id) : null,
            'service_slug' => $serviceSlug,
            'bound_type' => (string) ($row->bound_type ?? 'site'),
            'status' => (string) ($row->status ?? 'active'),
            'settings' => $sanitizedSettings,
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
        $serviceSlug = (string) ($record['service_slug'] ?? '');
        $service = Service::query()->where('slug', $serviceSlug)->first();

        if (! $service instanceof Service) {
            $run->recordWarning('site_services', $ref, "Service slug [{$serviceSlug}] not found in target.", $partFile, $recordIndex);

            return;
        }

        $siteId = null;
        if (! empty($record['site_ref'])) {
            $siteId = $refMap->get((string) $record['site_ref']);
        }

        $userId = null;
        if (! empty($record['user_ref'])) {
            $userId = $refMap->get((string) $record['user_ref']);
        }

        try {
            $existing = SiteService::query()
                ->where('service_id', $service->id)
                ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
                ->first();

            if ($existing instanceof SiteService) {
                $existing->status = (string) ($record['status'] ?? 'active');
                if (! empty($record['settings'])) {
                    $existing->settings = array_merge((array) ($existing->settings ?? []), (array) $record['settings']);
                }
                $existing->save();
                $refMap->set($ref, 'site_service', (int) $existing->id);
                $run->recordImported('site_services', $ref, $partFile, $recordIndex);

                return;
            }

            $siteService = new SiteService;
            $siteService->site_id = $siteId;
            $siteService->user_id = $userId;
            $siteService->service_id = (int) $service->id;
            $siteService->bound_type = (string) ($record['bound_type'] ?? 'site');
            $siteService->status = (string) ($record['status'] ?? 'active');
            $siteService->settings = (array) ($record['settings'] ?? []);
            $siteService->save();

            $refMap->set($ref, 'site_service', (int) $siteService->id);
            $run->recordImported('site_services', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('site_services', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function sanitizeSettings(array $settings): array
    {
        $sanitized = [];
        $secretKeyPatterns = ['password', 'secret', 'token', 'key', 'auth'];

        foreach ($settings as $key => $value) {
            $lower = strtolower($key);
            $isSecret = false;
            foreach ($secretKeyPatterns as $pattern) {
                if (str_contains($lower, $pattern)) {
                    $isSecret = true;
                    break;
                }
            }

            if (! $isSecret) {
                $sanitized[$key] = is_array($value) ? $this->sanitizeSettings($value) : $value;
            }
        }

        return $sanitized;
    }
}

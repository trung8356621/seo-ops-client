<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Service;
use App\Models\User;
use App\Services\ServiceDatabaseConnectionResolver;
use App\Services\ServiceIdentity;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only Admin Service catalog overview (no entitlement controls).
 */
final class ServiceStatusOverview extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $slug = 'services';

    protected static string $view = 'filament.pages.service-status-overview';

    protected static ?int $navigationSort = 11;

    public static function getNavigationGroup(): ?string
    {
        return 'Hệ thống';
    }

    public static function getNavigationLabel(): string
    {
        return 'Dịch vụ';
    }

    public function getTitle(): string
    {
        return 'Dịch vụ';
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && in_array((string) $user->role, [User::ROLE_OWNER, User::ROLE_ADMIN], true);
    }

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return static::canAccess();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function serviceCards(): array
    {
        $resolver = app(ServiceDatabaseConnectionResolver::class);
        $cards = [];

        foreach (ServiceIdentity::knownPublicSlugs() as $publicSlug) {
            $service = ServiceIdentity::findService($publicSlug);
            if (! $service instanceof Service && in_array($publicSlug, [
                ServiceIdentity::PUBLIC_SEEDING,
                ServiceIdentity::PUBLIC_SOCIAL_AUTOMATION,
            ], true)) {
                continue;
            }

            $health = $resolver->healthReport($publicSlug);
            $cards[] = [
                'slug' => $publicSlug,
                'name' => $health['name'],
                'active' => $health['active'],
                'exists' => $service instanceof Service,
                'key_label' => $health['key_provisioned'] ? 'Key ✓' : 'Key chưa provision',
                'db_connection' => $health['db_connection'],
                'db_label' => $health['readiness_label'] ?? 'Chưa cấu hình',
                'connection_source' => $health['connection_source'] ?? 'unavailable',
                'open_url' => ServiceIdentity::openUrl($publicSlug),
                'setup_url' => ServiceConfigure::getUrl(['service' => $publicSlug]),
            ];
        }

        return $cards;
    }

    /**
     * Connection and entitlement status with authoritative OPS-SERVER.
     *
     * @return array<string, mixed>
     */
    public function opsServerConnectionReport(): array
    {
        $installations = app(\App\Services\H1\Persistence\InstallationStateRepository::class);
        $installation = $installations->currentInstallation();
        $cred = $installation ? $installations->activeCredential($installation->installation_id) : null;

        if (! $installation instanceof \App\Models\H1Installation) {
            return [
                'enrolled' => false,
                'status_label' => 'Chưa liên kết OPS-SERVER (Unenrolled)',
                'status_badge' => 'gray',
                'installation_id' => '—',
                'control_server_url' => (string) config('h1-license.base_url', '—'),
                'credential_version' => '—',
                'last_reconciled' => 'Chưa có dữ liệu',
                'entitlement_revision' => 0,
                'seo_status' => 'Chưa cấu hình',
                'seo_quota' => '—',
                'seo_valid_until' => '—',
                'seeding_status' => 'Chưa cấu hình',
                'seeding_valid_until' => '—',
                'golden_status' => 'Chưa đồng bộ',
                'golden_version' => '1.0.0',
                'last_error' => null,
            ];
        }

        $services = is_array($installation->verified_snapshot['services'] ?? null)
            ? $installation->verified_snapshot['services']
            : [];

        $seo = $services['seo'] ?? [];
        $seoActive = ! empty($seo['enabled']) && empty($seo['revoked_at']);
        $seeding = $services['seeding'] ?? [];
        $seedingActive = ! empty($seeding['enabled']) && empty($seeding['revoked_at']);

        // Golden Configuration sync state
        $goldenOutcome = 'Chưa đồng bộ';
        $goldenVersion = '1.0.0';
        try {
            $stateStore = app(\Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigurationSyncStateStore::class);
            $state = $stateStore->read();
            if (isset($state['last_compare']['outcome'])) {
                $goldenOutcome = (string) $state['last_compare']['outcome'];
            }
        } catch (\Throwable) {}

        // Last error
        $lastError = null;
        try {
            $failedReceipt = \App\Models\H1CommandReceipt::query()
                ->where('result', 'failed')
                ->latest('id')
                ->first();
            if ($failedReceipt instanceof \App\Models\H1CommandReceipt) {
                $lastError = "{$failedReceipt->command_id}: " . ($failedReceipt->reason ?? 'Lỗi không xác định');
            }
        } catch (\Throwable) {}

        $statusLabel = $installation->validated_at ? 'Đã liên kết & Đồng bộ (Active)' : 'Chờ đồng bộ lần đầu (Pending First Reconcile)';
        $statusBadge = $installation->validated_at ? 'emerald' : 'amber';

        return [
            'enrolled' => true,
            'status_label' => $statusLabel,
            'status_badge' => $statusBadge,
            'installation_id' => $installation->installation_id,
            'control_server_url' => (string) ($installation->control_server_url ?: config('h1-license.base_url', '—')),
            'credential_version' => $installation->credential_version ? "v{$installation->credential_version}" : ($cred ? "v{$cred->version}" : '—'),
            'last_reconciled' => $installation->validated_at ? $installation->validated_at->format('Y-m-d H:i:s T') : 'Chưa có dữ liệu',
            'entitlement_revision' => (int) $installation->entitlement_revision,
            'seo_status' => $seoActive ? 'Active' : (empty($seo['enabled']) ? 'Inactive' : 'Revoked'),
            'seo_quota' => isset($seo['quota']) ? "{$seo['quota']} domains" : 'Không giới hạn',
            'seo_valid_until' => ! empty($seo['valid_until']) ? (string) $seo['valid_until'] : '—',
            'seeding_status' => $seedingActive ? 'Active' : (empty($seeding['enabled']) ? 'Inactive' : 'Revoked'),
            'seeding_valid_until' => ! empty($seeding['valid_until']) ? (string) $seeding['valid_until'] : '—',
            'golden_status' => $goldenOutcome,
            'golden_version' => $goldenVersion,
            'last_error' => $lastError,
        ];
    }
}

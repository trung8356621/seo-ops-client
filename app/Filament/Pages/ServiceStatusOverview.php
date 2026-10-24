<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Service;
use App\Models\User;
use App\Services\H1\Enforcement\ServiceEntitlementGate;
use App\Services\H1\Exceptions\H1TransportException;
use App\Services\H1\Notifications\ClientNotificationStore;
use App\Services\H1\Persistence\InstallationStateRepository;
use App\Services\H1\Reconcile\LicenseReconcileService;
use App\Services\H1\Snapshot\EntitlementSnapshotMapper;
use App\Services\ServiceDatabaseConnectionResolver;
use App\Services\ServiceIdentity;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Admin Service catalog overview — H1 connection ≠ service entitlement ≠ operational.
 */
final class ServiceStatusOverview extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $slug = 'services';

    protected static string $view = 'filament.pages.service-status-overview';

    protected static ?int $navigationSort = 11;

    public bool $reconcileRunning = false;

    public ?string $reconcileError = null;

    private bool $autoReconcileCompleted = false;

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
     * Pull pending entitlement commands when opening Services (H1 connected ≠ up to date).
     */
    public function mountAutoReconcile(): void
    {
        if ($this->autoReconcileCompleted || $this->reconcileRunning) {
            return;
        }
        $this->autoReconcileCompleted = true;
        $this->runReconcile(notify: false);
    }

    public function reconcileLicense(): void
    {
        $this->runReconcile(notify: true);
    }

    private function runReconcile(bool $notify): void
    {
        if ($this->reconcileRunning) {
            return;
        }

        $repo = app(InstallationStateRepository::class);
        if (! $repo->isEnrolled()) {
            $this->reconcileError = null;

            return;
        }

        $this->reconcileRunning = true;
        $this->reconcileError = null;

        try {
            $result = app(LicenseReconcileService::class)->reconcile();
            if (! empty($result['offline'])) {
                $this->reconcileError = 'Không kết nối được OPS-SERVER. Đang dùng bản quyền đã xác minh gần nhất (nếu có).';
            } elseif ($notify) {
                Notification::make()
                    ->title('Đã đồng bộ quyền H1')
                    ->body('Áp dụng '.((int) ($result['commands_applied'] ?? 0)).' lệnh entitlement.')
                    ->success()
                    ->send();
            }
        } catch (H1TransportException $e) {
            $this->reconcileError = $e->getMessage();
            if ($notify) {
                Notification::make()
                    ->title('Đồng bộ H1 thất bại')
                    ->body($e->getMessage())
                    ->danger()
                    ->send();
            }
        } finally {
            $this->reconcileRunning = false;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function serviceCards(): array
    {
        $resolver = app(ServiceDatabaseConnectionResolver::class);
        $gate = app(ServiceEntitlementGate::class);
        $activeByService = [];
        foreach ($this->activeClientNotifications() as $ntf) {
            $key = is_string($ntf['service_key'] ?? null) ? (string) $ntf['service_key'] : '';
            if ($key !== '' && ! isset($activeByService[$key])) {
                $activeByService[$key] = $ntf;
            }
        }
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
            $entitlementState = (string) ($health['entitlement_state'] ?? $gate->state($publicSlug));
            $entitlementLabel = match ($entitlementState) {
                'allowed' => 'Quyền dịch vụ: Đã cấp',
                'suspended' => 'Quyền dịch vụ: Tạm ngưng / Thu hồi',
                default => 'Quyền dịch vụ: Chưa cấp',
            };
            $ntf = $activeByService[$publicSlug] ?? null;

            $cards[] = [
                'slug' => $publicSlug,
                'name' => $health['name'],
                'exists' => $service instanceof Service,
                'status' => $health['status'] ?? 'needs_config',
                'status_label' => $health['status_label'] ?? 'Chưa cấu hình',
                'entitlement_label' => $entitlementLabel,
                'entitlement_state' => $entitlementState,
                'client_message' => is_array($ntf) ? (string) ($ntf['message'] ?? '') : '',
                'client_message_action' => is_array($ntf) ? (string) ($ntf['action'] ?? '') : '',
                'db_label' => $health['readiness_label'] ?? 'Chưa cấu hình DB',
                'setup_url' => ServiceConfigure::getUrl(['service' => $publicSlug]),
            ];
        }

        return $cards;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeClientNotifications(): array
    {
        $installation = app(InstallationStateRepository::class)->currentInstallation();
        if (! $installation instanceof \App\Models\H1Installation) {
            return [];
        }

        return app(ClientNotificationStore::class)->activeForInstallation($installation->installation_id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function clientNotificationHistory(): array
    {
        $installation = app(InstallationStateRepository::class)->currentInstallation();
        if (! $installation instanceof \App\Models\H1Installation) {
            return [];
        }

        return app(ClientNotificationStore::class)->recentHistory($installation->installation_id, 15);
    }

    /**
     * Connection and entitlement status with authoritative OPS-SERVER.
     *
     * @return array<string, mixed>
     */
    public function opsServerConnectionReport(): array
    {
        $installations = app(InstallationStateRepository::class);
        $installation = $installations->currentInstallation();
        $cred = $installation ? $installations->activeCredential($installation->installation_id) : null;
        $mapper = app(EntitlementSnapshotMapper::class);

        if (! $installation instanceof \App\Models\H1Installation) {
            return [
                'enrolled' => false,
                'h1_connected' => false,
                'status_label' => 'H1: Chưa liên kết OPS-SERVER',
                'status_badge' => 'gray',
                'installation_id' => '—',
                'control_server_url' => (string) config('h1-license.base_url', '—'),
                'credential_version' => '—',
                'last_reconciled' => 'Chưa có dữ liệu',
                'entitlement_revision' => 0,
                'seo_status' => 'Chưa cấp',
                'seo_quota' => '—',
                'seo_valid_until' => '—',
                'seeding_status' => 'Chưa cấp',
                'seeding_valid_until' => '—',
                'social_automation_status' => 'Chưa cấp',
                'golden_status' => 'Chưa đồng bộ',
                'golden_version' => '1.0.0',
                'last_error' => $this->reconcileError,
                'reconcile_running' => $this->reconcileRunning,
            ];
        }

        $services = is_array($installation->verified_snapshot['services'] ?? null)
            ? $installation->verified_snapshot['services']
            : [];

        $serviceStatus = static function (array $services, string $key) use ($mapper): string {
            $row = $services[$key] ?? null;
            if (! is_array($row)) {
                return 'Chưa cấp';
            }
            if (! empty($row['revoked_at'])) {
                return 'Thu hồi';
            }
            if ($mapper->serviceCurrentlyLicensed($row)) {
                return 'Đã cấp';
            }

            return ! empty($row['enabled']) ? 'Hết hạn' : 'Tắt';
        };

        $goldenOutcome = 'Chưa đồng bộ';
        $goldenVersion = '1.0.0';
        try {
            $stateStore = app(\Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigurationSyncStateStore::class);
            $state = $stateStore->read();
            if (isset($state['last_compare']['outcome'])) {
                $goldenOutcome = (string) $state['last_compare']['outcome'];
            }
        } catch (\Throwable) {
        }

        $lastError = $this->reconcileError;
        try {
            $failedReceipt = \App\Models\H1CommandReceipt::query()
                ->where('result', 'failed')
                ->latest('id')
                ->first();
            if ($failedReceipt instanceof \App\Models\H1CommandReceipt && $lastError === null) {
                $lastError = "{$failedReceipt->command_id}: ".($failedReceipt->reason ?? 'Lỗi không xác định');
            }
        } catch (\Throwable) {
        }

        $h1Connected = $cred !== null;
        $statusLabel = $h1Connected
            ? 'H1: Đã kết nối (enrollment) — quyền dịch vụ theo snapshot bên dưới'
            : 'H1: Chờ credential';
        $statusBadge = $h1Connected ? 'emerald' : 'amber';

        return [
            'enrolled' => $h1Connected,
            'h1_connected' => $h1Connected,
            'status_label' => $statusLabel,
            'status_badge' => $statusBadge,
            'installation_id' => $installation->installation_id,
            'control_server_url' => (string) ($installation->control_server_url ?: config('h1-license.base_url', '—')),
            'credential_version' => $installation->credential_version ? "v{$installation->credential_version}" : ($cred ? "v{$cred->version}" : '—'),
            'last_reconciled' => $installation->validated_at ? $installation->validated_at->format('Y-m-d H:i:s T') : 'Chưa có dữ liệu',
            'entitlement_revision' => (int) $installation->entitlement_revision,
            'seo_status' => $serviceStatus($services, 'seo'),
            'seo_quota' => isset($services['seo']['quota']) ? "{$services['seo']['quota']} domains" : '—',
            'seo_valid_until' => ! empty($services['seo']['valid_until']) ? (string) $services['seo']['valid_until'] : '—',
            'seeding_status' => $serviceStatus($services, 'seeding'),
            'seeding_valid_until' => ! empty($services['seeding']['valid_until']) ? (string) $services['seeding']['valid_until'] : '—',
            'social_automation_status' => $serviceStatus($services, 'social-automation'),
            'golden_status' => $goldenOutcome,
            'golden_version' => $goldenVersion,
            'last_error' => $lastError,
            'reconcile_running' => $this->reconcileRunning,
        ];
    }
}

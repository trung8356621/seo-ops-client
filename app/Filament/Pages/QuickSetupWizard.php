<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\ApiConnection;
use App\Models\H1Installation;
use App\Models\Site;
use App\Models\User;
use App\Models\WpOption;
use App\Services\AaPanelCron\AaPanelCronCatalog;
use App\Services\H1\Endpoint\ServerEndpointValidator;
use App\Services\H1\Enforcement\ServiceEntitlementGate;
use App\Services\H1\Enrollment\EnrollmentConsumer;
use App\Services\H1\Exceptions\H1EnrollmentException;
use App\Services\H1\Exceptions\H1TransportException;
use App\Services\H1\Persistence\InstallationStateRepository;
use App\Services\H1\Reconcile\LicenseReconcileService;
use App\Services\H1\Snapshot\EntitlementSnapshotMapper;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderConnectionTester;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\BlankInstallationDetector;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigurationSyncCoordinator;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\GoldenBootstrapService;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\SeoEntitlementProbe;

/**
 * Admin Quick Setup Wizard (Rank Math-style onboarding).
 * Remains accessible post-onboarding for review and configuration updates.
 */
class QuickSetupWizard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';

    protected static ?string $slug = 'quick-setup';

    protected static string $view = 'filament.pages.quick-setup-wizard';

    protected static ?int $navigationSort = 2;

    public int $currentStep = 1;

    // STEP 1 Properties
    public string $serverUrl = '';
    public ?string $serverConnectionStatus = null; // success, error
    public ?string $serverConnectionMessage = null;
    public string $enrollmentToken = '';
    public ?string $enrollmentError = null;
    public ?string $enrollmentErrorCode = null;
    public bool $enrollmentSuccess = false;
    public bool $isEnrolled = false;
    public ?array $installationDetails = null;

    public const MAX_STEP = 5;

    public const COMMERCIAL_GATE_MESSAGE = 'Chưa có dịch vụ được kích hoạt. Vui lòng liên hệ quản trị viên hoặc đồng bộ lại quyền sử dụng.';

    /**
     * Commercial services for Step 1 badges (seo / seeding / social-automation).
     * Sourced only from verified H1 snapshot — never from enrollment alone; never includes agent.
     *
     * @var list<array{key: string, label: string, enabled: bool, quota_label: ?string}>
     */
    public array $commercialServices = [];

    public bool $hasCommercialServiceEnabled = false;

    public ?string $commercialGateMessage = null;

    /** True while License Reconcile is in flight (locks Continue). */
    public bool $reconcileRunning = false;

    /** Connection/timeout error from last reconcile attempt (null when OK). */
    public ?string $reconcileError = null;

    /** @var 'CONNECTED'|'INSTALLATION_REVOKED'|'INSTALLATION_NOT_FOUND'|'CONNECTION_ERROR'|'RECONNECTING'|null */
    public ?string $connectionState = null;

    public ?string $connectionReason = null;

    public bool $showReconnectConfirm = false;

    /**
     * Prevents duplicate auto-reconcile from Livewire rerender / step navigation.
     * Reset only on explicit Retry after failure is not required — Retry calls reconcile directly.
     */
    public bool $step1AutoReconcileCompleted = false;

    // STEP 2 Properties
    /** Commercial SEO from verified snapshot (checklist / Site Management). */
    public bool $isSeoLicensed = false;
    /**
     * Golden-eligible SEO entitlement from {@see SeoEntitlementProbe} / local presentation store.
     * Required for Apply only — Download uses enrollment credential.
     */
    public bool $goldenSeoEntitled = false;
    public string $goldenStatus = 'blank'; // blank, in_sync, customized, unlicensed, error
    public ?string $goldenReason = null;
    public array $goldenCounts = ['prompts' => 0, 'workflows' => 0, 'bindings' => 0];
    public ?string $goldenError = null;
    public bool $goldenSuccess = false;
    /**
     * Apply overwrite consent. Auto-true on blank; false on customized until user checks.
     * Checkbox is only shown when status is customized.
     */
    public bool $overwriteGoldenConfiguration = true;
    /** Guards Step 2 continue against duplicate Livewire submissions while bootstrap runs. */
    public bool $goldenApplying = false;
    public bool $goldenDownloading = false;
    /** none | pending_apply | applied | error */
    public string $goldenPackageStatus = 'none';
    public ?string $goldenPackageVersion = null;
    public ?string $goldenDownloadMessage = null;

    // STEP 3 Properties
    public string $aiProvider = 'gemini';
    public string $aiConnectionName = '';
    public string $aiApiKey = '';
    public ?string $aiTestStatus = null; // success, error
    public ?string $aiTestDetails = null;
    public array $existingAiConnections = [];

    /** Checklist / Site Management summary (no Quick Setup website creation step). */
    public array $existingSites = [];

    // STEP 5 Properties (completion / summary)
    public array $checklist = [];

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.system');
    }

    public static function getNavigationLabel(): string
    {
        return 'Thiết lập nhanh';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Thiết lập nhanh SEO-OPS';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Hướng dẫn cấu hình từng bước ban đầu cho hệ thống SEO-OPS Client.';
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

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $requestedStep = (int) request()->query('step', 1);
        // Clamp out-of-range only. Do not remap completed installs or wipe progress markers.
        if ($requestedStep > self::MAX_STEP) {
            $requestedStep = self::MAX_STEP;
        }
        if ($requestedStep >= 1 && $requestedStep <= self::MAX_STEP) {
            $this->currentStep = $requestedStep;
        }

        $this->loadAllStepData();
        $this->enforceCommercialStepGate();
        if ($this->currentStep === 2) {
            $this->runAutomaticGoldenLifecycle();
        }
    }

    public function hydrate(): void
    {
        $this->enforceCommercialStepGate();
    }

    public function updatedCurrentStep(mixed $value): void
    {
        unset($value);
        $this->enforceCommercialStepGate();
    }

    protected function getViewData(): array
    {
        return [
            'currentStep' => $this->currentStep,
            'isEnrolled' => $this->isEnrolled,
            'installationDetails' => $this->installationDetails,
            'serverUrl' => $this->serverUrl,
            'serverConnectionStatus' => $this->serverConnectionStatus,
            'serverConnectionMessage' => $this->serverConnectionMessage,
            'enrollmentToken' => $this->enrollmentToken,
            'enrollmentError' => $this->enrollmentError,
            'enrollmentSuccess' => $this->enrollmentSuccess,
            'commercialServices' => $this->commercialServices,
            'hasCommercialServiceEnabled' => $this->hasCommercialServiceEnabled,
            'commercialGateMessage' => $this->commercialGateMessage,
            'reconcileRunning' => $this->reconcileRunning,
            'reconcileError' => $this->reconcileError,
            'connectionState' => $this->connectionState,
            'connectionReason' => $this->connectionReason,
            'showReconnectConfirm' => $this->showReconnectConfirm,
            'step1AutoReconcileCompleted' => $this->step1AutoReconcileCompleted,
            'isSeoLicensed' => $this->isSeoLicensed,
            'goldenSeoEntitled' => $this->goldenSeoEntitled,
            'goldenStatus' => $this->goldenStatus,
            'goldenReason' => $this->goldenReason,
            'goldenCounts' => $this->goldenCounts,
            'goldenError' => $this->goldenError,
            'goldenSuccess' => $this->goldenSuccess,
            'overwriteGoldenConfiguration' => $this->overwriteGoldenConfiguration,
            'goldenApplying' => $this->goldenApplying,
            'goldenDownloading' => $this->goldenDownloading,
            'goldenPackageStatus' => $this->goldenPackageStatus,
            'goldenPackageVersion' => $this->goldenPackageVersion,
            'goldenDownloadMessage' => $this->goldenDownloadMessage,
            'aiProvider' => $this->aiProvider,
            'aiConnectionName' => $this->aiConnectionName,
            'aiApiKey' => $this->aiApiKey,
            'aiTestStatus' => $this->aiTestStatus,
            'aiTestDetails' => $this->aiTestDetails,
            'existingAiConnections' => $this->existingAiConnections,
            'existingSites' => $this->existingSites,
            'checklist' => $this->checklist,
        ];
    }

    public function loadAllStepData(): void
    {
        $this->loadStep1Data();
        $this->loadStep2Data();
        $this->loadStep3Data();
        $this->loadSitesSummary();
        $this->loadStep5Data();
    }

    // ==========================================
    // STEP 1 — KÍCH HOẠT DỊCH VỤ
    // ==========================================

    public function loadStep1Data(): void
    {
        $installations = app(InstallationStateRepository::class);
        $installation = $installations->currentInstallation();
        $this->isEnrolled = $installations->isEnrolled();
        $this->serverUrl = $installations->getEffectiveControlServerUrl();
        $this->refreshCommercialServices();

        if ($installation instanceof H1Installation && $this->isEnrolled) {
            $cred = $installations->activeCredential($installation->installation_id);
            $seo = $this->commercialServiceRow('seo');
            $seeding = $this->commercialServiceRow('seeding');
            $social = $this->commercialServiceRow('social-automation');
            $snap = is_array($installation->verified_snapshot) ? $installation->verified_snapshot : [];
            if (
                ! empty($snap['revoked_at'])
                && ! in_array($this->connectionState, ['CONNECTION_ERROR', 'RECONNECTING'], true)
            ) {
                $this->connectionState = 'INSTALLATION_REVOKED';
                $this->connectionReason = is_string($snap['lifecycle_reason'] ?? null)
                    ? (string) $snap['lifecycle_reason']
                    : ($this->connectionReason ?: 'Installation đã bị thu hồi trên OPS-SERVER.');
            }

            $this->installationDetails = [
                'installation_id' => $installation->installation_id,
                'control_server_url' => (string) ($installation->control_server_url ?: config('h1-license.base_url', '—')),
                'credential_version' => $installation->credential_version ? "v{$installation->credential_version}" : ($cred ? "v{$cred->version}" : 'v1'),
                'last_reconciled' => $installation->validated_at ? $installation->validated_at->format('d/m/Y H:i:s') : 'Chưa có',
                'seo_active' => (bool) ($seo['enabled'] ?? false),
                'seo_quota' => $seo['quota_label'] ?? null,
                'seeding_active' => (bool) ($seeding['enabled'] ?? false),
                'social_active' => (bool) ($social['enabled'] ?? false),
                'snapshot_stale' => in_array($this->connectionState, ['INSTALLATION_REVOKED', 'INSTALLATION_NOT_FOUND'], true)
                    || ! empty($snap['revoked_at']),
            ];
        } else {
            $this->installationDetails = null;
        }

        if ($this->hasCommercialServiceEnabled) {
            $this->commercialGateMessage = null;
        }
    }

    /**
     * Verified H1 commercial entitlements only (seo / seeding / social-automation).
     * Agent / GLOBAL and enrollment placeholders are omitted — empty until a real grant exists.
     */
    public function refreshCommercialServices(): void
    {
        $catalog = [
            'seo' => 'SEO',
            'seeding' => 'Seeding',
            'social-automation' => 'Social Automation',
        ];

        $this->commercialServices = [];
        $this->hasCommercialServiceEnabled = false;
        $this->isSeoLicensed = false;

        $installations = app(InstallationStateRepository::class);
        $installation = $installations->currentInstallation();
        $mapper = app(EntitlementSnapshotMapper::class);

        $snapshot = ($installation instanceof H1Installation && is_array($installation->verified_snapshot))
            ? $installation->verified_snapshot
            : null;
        $productAllows = $this->productSnapshotAllowsCommercial($snapshot, $mapper);
        $services = is_array($snapshot['services'] ?? null) ? $snapshot['services'] : [];

        foreach ($catalog as $key => $label) {
            $row = is_array($services[$key] ?? null) ? $services[$key] : null;
            if (! is_array($row) || ! $mapper->hasPriorCommercialEntitlement(array_merge($row, ['key' => $key]))) {
                continue;
            }

            $status = $mapper->commercialEntitlementStatus($row);
            $enabled = $productAllows
                && $status === 'active'
                && $this->servicePassesProductDeadline($row, $snapshot, $mapper);
            if (! $productAllows && $status === 'active') {
                $status = 'expired';
            } elseif ($enabled) {
                $status = 'active';
            } elseif ($status === 'active' && ! $this->servicePassesProductDeadline($row, $snapshot, $mapper)) {
                $status = 'expired';
            }

            $quotaLabel = null;
            if (array_key_exists('quota', $row) && is_numeric($row['quota'])) {
                $quotaLabel = $key === 'seo'
                    ? ((int) $row['quota']).' domains'
                    : (string) (int) $row['quota'];
            }

            $this->commercialServices[] = [
                'key' => $key,
                'label' => $label,
                'enabled' => $enabled,
                'status' => $status,
                'quota_label' => $quotaLabel,
            ];

            if ($enabled) {
                $this->hasCommercialServiceEnabled = true;
            }
            if ($key === 'seo') {
                $this->isSeoLicensed = $enabled;
            }
        }
    }

    public function canContinuePastCommercialGate(): bool
    {
        $this->refreshCommercialServices();

        return $this->hasCommercialServiceEnabled;
    }

    /**
     * Block Step 2–5 when no commercial service is Enabled (URL / goToStep / Livewire state).
     */
    public function enforceCommercialStepGate(): void
    {
        $this->refreshCommercialServices();
        if ($this->currentStep > 1 && ! $this->hasCommercialServiceEnabled) {
            $this->currentStep = 1;
            $this->commercialGateMessage = self::COMMERCIAL_GATE_MESSAGE;
        }
    }

    /**
     * @return array{key: string, label: string, enabled: bool, quota_label: ?string}|null
     */
    private function commercialServiceRow(string $key): ?array
    {
        foreach ($this->commercialServices as $row) {
            if (($row['key'] ?? '') === $key) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Product-level revoke blocks all commercial services. Expiry is a per-service backstop.
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    private function productSnapshotAllowsCommercial(?array $snapshot, EntitlementSnapshotMapper $mapper): bool
    {
        unset($mapper);

        return $snapshot !== null && empty($snapshot['revoked_at']);
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  array<string, mixed>|null  $snapshot
     */
    private function servicePassesProductDeadline(array $service, ?array $snapshot, EntitlementSnapshotMapper $mapper): bool
    {
        if ($snapshot === null) {
            return false;
        }
        if (! empty($service['valid_until']) || ! empty($service['grace_until'])) {
            return true;
        }
        $deadline = $snapshot['grace_until'] ?? $snapshot['valid_until'] ?? null;
        if (is_string($deadline) && $deadline !== '' && ! $mapper->timestampAllows($deadline)) {
            return false;
        }

        return true;
    }

    public function testServerConnection(): void
    {
        $this->serverConnectionStatus = null;
        $this->serverConnectionMessage = null;

        $installations = app(InstallationStateRepository::class);

        $url = trim($this->serverUrl);
        if ($url === '') {
            $this->serverConnectionStatus = 'error';
            $this->serverConnectionMessage = 'Vui lòng nhập địa chỉ máy chủ OPS-SERVER.';
            return;
        }

        try {
            $normalized = ServerEndpointValidator::validate($url);
            $this->serverUrl = $normalized;

            // Save pre-enrollment endpoint securely per Installation
            $installations->saveConfiguredControlServerUrl($normalized);

            $response = \Illuminate\Support\Facades\Http::timeout(5)->get($normalized . '/api/health');

            if ($response->successful()) {
                $this->serverConnectionStatus = 'success';
                $this->serverConnectionMessage = 'Kết nối thành công. OPS-SERVER đang hoạt động bình thường.';

                Notification::make()
                    ->title('Kết nối OPS-SERVER thành công!')
                    ->body('Máy chủ tại ' . $normalized . ' phản hồi sẵn sàng.')
                    ->success()
                    ->send();
            } else {
                $this->serverConnectionStatus = 'error';
                $this->serverConnectionMessage = 'Máy chủ phản hồi mã lỗi HTTP ' . $response->status() . '.';

                Notification::make()
                    ->title('Kết nối OPS-SERVER thất bại')
                    ->body($this->serverConnectionMessage)
                    ->danger()
                    ->send();
            }
        } catch (\InvalidArgumentException $e) {
            $this->serverConnectionStatus = 'error';
            $this->serverConnectionMessage = $e->getMessage();

            Notification::make()
                ->title('Địa chỉ máy chủ không hợp lệ')
                ->body($this->serverConnectionMessage)
                ->danger()
                ->send();
        } catch (\Throwable $e) {
            $this->serverConnectionStatus = 'error';
            $msg = $e->getMessage();
            if (str_contains($msg, 'Connection refused') || str_contains($msg, 'cURL error 7')) {
                $this->serverConnectionMessage = 'Không thể kết nối đến máy chủ tại ' . $url . ' (Máy chủ chưa khởi động hoặc sai cổng).';
            } elseif (str_contains($msg, 'timed out') || str_contains($msg, 'cURL error 28')) {
                $this->serverConnectionMessage = 'Kết nối tới máy chủ quá thời gian chờ (Timeout).';
            } elseif (str_contains($msg, 'SSL') || str_contains($msg, 'certificate')) {
                $this->serverConnectionMessage = 'Lỗi bảo mật chứng chỉ SSL khi kết nối tới máy chủ.';
            } else {
                $this->serverConnectionMessage = 'Lỗi kết nối máy chủ: ' . $msg;
            }

            Notification::make()
                ->title('Lỗi kết nối máy chủ')
                ->body($this->serverConnectionMessage)
                ->danger()
                ->send();
        }
    }

    public function activateEnrollment(): void
    {
        $this->enrollmentError = null;
        $this->enrollmentErrorCode = null;
        $this->enrollmentSuccess = false;

        $installations = app(InstallationStateRepository::class);
        $alreadyEnrolled = $installations->isEnrolled();

        $url = trim($this->serverUrl);
        if ($url === '') {
            $this->enrollmentError = 'Vui lòng nhập địa chỉ máy chủ OPS-SERVER.';
            return;
        }

        try {
            $normalized = ServerEndpointValidator::validate($url);
            $this->serverUrl = $normalized;
            // Recovery Mode may retarget OPS URL; connected installs still block casual migration.
            if (
                $alreadyEnrolled
                && in_array($this->connectionState, ['INSTALLATION_REVOKED', 'INSTALLATION_NOT_FOUND'], true)
            ) {
                $installation = $installations->currentInstallation();
                if ($installation instanceof H1Installation) {
                    $installation->control_server_url = $normalized;
                    $installation->save();
                }
                try {
                    \App\Models\WpOption::set('h1_control_server_url', $normalized);
                } catch (\Throwable) {
                }
                config([
                    'h1-license.base_url' => $normalized,
                    'configuration-sync.base_url' => $normalized,
                ]);
            } else {
                $installations->saveConfiguredControlServerUrl($normalized);
            }
        } catch (H1EnrollmentException $e) {
            $this->enrollmentError = $e->getMessage();

            return;
        } catch (\InvalidArgumentException $e) {
            $this->enrollmentError = $e->getMessage();

            return;
        }

        $token = trim($this->enrollmentToken);
        if ($token === '') {
            $this->enrollmentError = 'Vui lòng nhập mã kích hoạt.';
            return;
        }

        if (! preg_match('/^tok_[A-Za-z0-9_-]{8,124}$/', $token)) {
            $this->enrollmentError = 'Mã kích hoạt không đúng định dạng. Mã phải bắt đầu bằng "tok_" kèm các ký tự Base64URL hợp lệ và không vượt quá 128 ký tự.';
            return;
        }

        try {
            $consumer = app(EnrollmentConsumer::class);
            // Same reconnect service as Services page — no Fresh Installer / seeder.
            $this->connectionState = $alreadyEnrolled ? 'RECONNECTING' : $this->connectionState;
            $result = $alreadyEnrolled
                ? $consumer->replaceInstallationKey($token)
                : $consumer->enroll($token);

            $this->enrollmentSuccess = true;
            $this->enrollmentToken = '';
            $this->showReconnectConfirm = false;
            $this->connectionState = 'CONNECTED';
            $this->connectionReason = null;
            $this->reconcileError = null;
            $this->step1AutoReconcileCompleted = false;
            $this->loadAllStepData();
            $this->maybeAutoReconcileOnStep1();

            Notification::make()
                ->title($alreadyEnrolled ? 'Đã kết nối lại OPS-SERVER' : 'Kích hoạt dịch vụ thành công!')
                ->body('Installation ID: ' . ($result['installation_id'] ?? ''))
                ->success()
                ->send();
        } catch (H1EnrollmentException $e) {
            $this->showReconnectConfirm = false;
            if ($this->connectionState === 'RECONNECTING') {
                $this->connectionState = 'INSTALLATION_REVOKED';
            }
            $this->enrollmentErrorCode = $e->errorCode;
            $this->enrollmentError = match ($e->errorCode) {
                'invalid_receipt' => 'Mã kích hoạt không hợp lệ (phải bắt đầu bằng "tok_" và đúng định dạng).',
                'token_expired', 'receipt_expired' => 'Mã kích hoạt đã hết hạn. Vui lòng tạo mã mới trên OPS-SERVER.',
                'token_used' => 'Mã kích hoạt này đã được sử dụng trước đó.',
                'already_enrolled' => 'Thiết bị này đã được liên kết với một ID cài đặt khác.',
                'server_unconfigured' => 'Địa chỉ máy chủ OPS-SERVER chưa được cấu hình.',
                'insecure_transport' => 'Địa chỉ máy chủ yêu cầu sử dụng kết nối bảo mật HTTPS.',
                'server_offline' => 'Không thể kết nối đến máy chủ OPS-SERVER tại ' . $this->serverUrl . '.',
                default => 'Lỗi kích hoạt [' . $e->errorCode . ']: ' . $e->getMessage(),
            };

            Notification::make()
                ->title('Kích hoạt không thành công')
                ->body($this->enrollmentError)
                ->danger()
                ->send();
        } catch (\Throwable $e) {
            $this->showReconnectConfirm = false;
            if ($this->connectionState === 'RECONNECTING') {
                $this->connectionState = 'INSTALLATION_REVOKED';
            }
            $this->enrollmentError = 'Không thể kết nối đến máy chủ OPS-SERVER: ' . $e->getMessage();

            Notification::make()
                ->title('Lỗi kết nối')
                ->body($this->enrollmentError)
                ->danger()
                ->send();
        }
    }

    public function prepareReconnect(): void
    {
        $token = trim($this->enrollmentToken);
        if ($token === '') {
            $this->enrollmentError = 'Vui lòng nhập Enrollment Token của Installation mới.';

            return;
        }
        if (! preg_match('/^tok_[A-Za-z0-9_-]{8,124}$/', $token)) {
            $this->enrollmentError = 'Mã kích hoạt không đúng định dạng. Mã phải bắt đầu bằng "tok_".';

            return;
        }
        $this->enrollmentError = null;
        $this->showReconnectConfirm = true;
    }

    public function cancelReconnectConfirm(): void
    {
        $this->showReconnectConfirm = false;
    }

    /**
     * Auto-reconcile when entering Step 1 (wire:init). Idempotent per component lifetime.
     */
    public function maybeAutoReconcileOnStep1(): void
    {
        if ($this->step1AutoReconcileCompleted || $this->reconcileRunning) {
            return;
        }

        if (! $this->isEnrolled) {
            $this->step1AutoReconcileCompleted = true;

            return;
        }

        $this->runStep1Reconcile();
    }

    /**
     * Manual Retry — always attempts reconcile (does not create orders / invent entitlements).
     */
    public function reconcileLicense(): void
    {
        if ($this->reconcileRunning) {
            return;
        }

        $this->runStep1Reconcile();
    }

    /**
     * Runs existing {@see LicenseReconcileService}; refreshes commercial badges from verified snapshot only.
     */
    public function runStep1Reconcile(): void
    {
        if ($this->reconcileRunning) {
            return;
        }

        if (! $this->isEnrolled) {
            $this->step1AutoReconcileCompleted = true;

            return;
        }

        $this->reconcileRunning = true;
        $this->reconcileError = null;

        try {
            $result = app(LicenseReconcileService::class)->reconcile();
            if ($result['offline'] ?? false) {
                $this->connectionState = 'CONNECTION_ERROR';
                $this->connectionReason = null;
                $this->reconcileError = 'Không kết nối được OPS-SERVER (offline hoặc timeout). Key hiện tại được giữ nguyên. Đang dùng bản quyền đã xác minh gần nhất (nếu có).';
            } else {
                $this->connectionState = 'CONNECTED';
                $this->connectionReason = null;
                $this->reconcileError = null;
            }
            $this->loadStep1Data();

            if (! ($result['offline'] ?? false) && ! $this->hasCommercialServiceEnabled) {
                $this->commercialGateMessage = self::COMMERCIAL_GATE_MESSAGE;
            }
        } catch (H1TransportException $e) {
            $this->applyReconcileTransportFailure($e);
            $this->loadStep1Data();
        } catch (\Throwable $e) {
            $this->connectionState = 'CONNECTION_ERROR';
            $this->reconcileError = $this->formatReconcileConnectionError($e);
            $this->loadStep1Data();
        } finally {
            $this->reconcileRunning = false;
            $this->step1AutoReconcileCompleted = true;
        }
    }

    private function applyReconcileTransportFailure(H1TransportException $e): void
    {
        $code = (string) $e->errorCode;
        if (in_array($code, ['installation_revoked', 'credential_inactive', 'installation_inactive'], true)) {
            $this->connectionState = 'INSTALLATION_REVOKED';
            $this->connectionReason = $e->getMessage();
            $this->reconcileError = null;
            $this->markLocalSnapshotRevoked($e->getMessage());

            return;
        }
        if (in_array($code, ['installation_not_found', 'unknown_credential'], true)) {
            $this->connectionState = 'INSTALLATION_NOT_FOUND';
            $this->connectionReason = $e->getMessage();
            $this->reconcileError = null;
            $this->markLocalSnapshotRevoked($e->getMessage());

            return;
        }
        if ($code === 'server_offline') {
            $this->connectionState = 'CONNECTION_ERROR';
            $this->connectionReason = null;
            $this->reconcileError = 'OPS-SERVER offline. Key hiện tại được giữ nguyên.';

            return;
        }

        $this->connectionState = 'CONNECTION_ERROR';
        $this->reconcileError = $this->formatReconcileConnectionError($e);
    }

    private function markLocalSnapshotRevoked(string $reason): void
    {
        $installation = app(InstallationStateRepository::class)->currentInstallation();
        if (! $installation instanceof H1Installation) {
            return;
        }
        $snap = is_array($installation->verified_snapshot) ? $installation->verified_snapshot : [];
        if (! empty($snap['revoked_at'])) {
            if ($this->connectionReason === null && is_string($snap['lifecycle_reason'] ?? null)) {
                $this->connectionReason = (string) $snap['lifecycle_reason'];
            }

            return;
        }
        $snap['revoked_at'] = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $snap['lifecycle_reason'] = $reason;
        $services = is_array($snap['services'] ?? null) ? $snap['services'] : [];
        foreach ($services as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $row['revoked_at'] = $snap['revoked_at'];
            $row['enabled'] = false;
            $services[$key] = $row;
        }
        $snap['services'] = $services;
        $installation->verified_snapshot = $snap;
        $installation->save();
    }

    private function formatReconcileConnectionError(\Throwable $e): string
    {
        $msg = $e->getMessage();
        if ($e instanceof H1TransportException) {
            $msg = $e->errorCode.': '.$msg;
        }
        if (str_contains($msg, 'timed out') || str_contains($msg, 'cURL error 28') || str_contains($msg, 'timeout')) {
            return 'Kết nối OPS-SERVER quá thời gian chờ (timeout). Key hiện tại được giữ nguyên. Vui lòng thử lại.';
        }
        if (str_contains($msg, 'Connection refused') || str_contains($msg, 'cURL error 7') || str_contains($msg, 'server_offline')) {
            return 'Không kết nối được OPS-SERVER. Key hiện tại được giữ nguyên. Vui lòng thử lại.';
        }

        return 'Lỗi đồng bộ bản quyền: '.$msg;
    }

    // ==========================================
    // STEP 2 — CẤU HÌNH MẶC ĐỊNH (GOLDEN CONFIG)
    // ==========================================

    public function loadStep2Data(): void
    {
        $userId = (int) Auth::id();
        $this->refreshCommercialServices();
        $this->refreshGoldenSeoEntitlement();
        $this->refreshGoldenPackageState();

        $promptsCount = 0;
        $workflowsCount = 0;
        $bindingsCount = 0;

        try {
            if (class_exists(\Omnichannel\Addons\AiPrompt\Models\SeoPrompt::class)) {
                $promptsCount = \Omnichannel\Addons\AiPrompt\Models\SeoPrompt::query()->where('user_id', $userId)->count();
            }
            if (class_exists(\Omnichannel\Addons\AiPrompt\Models\SeoTask::class)) {
                $workflowsCount = \Omnichannel\Addons\AiPrompt\Models\SeoTask::query()->where('user_id', $userId)->count();
            }
            if (class_exists(\Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService::class)) {
                $bindingsCount = count(app(\Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService::class)->getPromptHookBindings());
            }
        } catch (\Throwable) {
        }

        $this->goldenCounts = [
            'prompts' => $promptsCount,
            'workflows' => $workflowsCount,
            'bindings' => $bindingsCount,
        ];

        if (! $this->isEnrolled) {
            $this->goldenStatus = 'unlicensed';
            $this->goldenReason = 'Cần kích hoạt Installation (Enrollment) trước khi tải Golden Package từ OPS-SERVER.';

            return;
        }

        if (! $this->goldenSeoEntitled) {
            $this->goldenStatus = 'unlicensed';
            $this->goldenReason = $this->goldenPackageStatus === 'pending_apply'
                ? 'Golden đã tải. SEO chưa kích hoạt — bỏ qua Apply cấu hình SEO.'
                : 'Golden sẽ được tải tự động. SEO chưa kích hoạt — không Apply cấu hình SEO.';
        }

        try {
            $blankAssessor = app(BlankInstallationDetector::class);
            $assessment = $blankAssessor->assess($userId);

            if ($assessment['blank'] === true) {
                if ($this->goldenSeoEntitled) {
                    $this->goldenStatus = 'blank';
                    $this->goldenReason = 'Cấu hình trống — hệ thống sẽ tự Apply Golden sau khi tải package.';
                    $this->overwriteGoldenConfiguration = true;
                }
            } else {
                $coordinator = app(ConfigurationSyncCoordinator::class);
                $comparison = $coordinator->compare($userId);
                $outcome = $comparison['outcome'] ?? 'customized';

                if ($outcome === 'in_sync') {
                    $this->goldenStatus = 'in_sync';
                    $this->goldenReason = 'Cấu hình hiện tại khớp Golden Configuration.';
                } elseif ($this->goldenSeoEntitled) {
                    $this->goldenStatus = 'customized';
                    $this->goldenReason = 'Đã có cấu hình tùy chỉnh. Chỉ ghi đè khi bạn đồng ý bên dưới.';
                    $this->overwriteGoldenConfiguration = false;
                }
            }
        } catch (\Throwable $e) {
            if ($this->goldenSeoEntitled) {
                $this->goldenStatus = 'error';
                $this->goldenReason = 'Không thể kiểm tra cấu hình: '.$e->getMessage();
            }
        }
    }

    /**
     * On entering Step 2: download Golden if needed; auto-apply only when SEO + blank.
     */
    public function runAutomaticGoldenLifecycle(): void
    {
        if (! $this->isEnrolled || $this->goldenDownloading || $this->goldenApplying) {
            return;
        }

        $this->refreshGoldenSeoEntitlement();
        $this->refreshGoldenPackageState();

        if ($this->goldenPackageStatus === 'none' || $this->goldenPackageStatus === 'error') {
            $this->downloadGoldenPackage(quiet: true);
        }

        if (! $this->goldenSeoEntitled) {
            // Download-only path for Seeding / Social — not an install failure.
            if ($this->goldenError !== null && str_contains($this->goldenError, 'SEO')) {
                $this->goldenError = null;
            }

            return;
        }

        if (in_array($this->goldenStatus, ['in_sync', 'customized'], true) || $this->goldenPackageStatus === 'applied' || $this->goldenSuccess) {
            return;
        }

        if ($this->goldenStatus === 'blank' && in_array($this->goldenPackageStatus, ['pending_apply', 'applied'], true)) {
            $this->overwriteGoldenConfiguration = true;
            $this->goldenApplying = true;
            try {
                $this->applyGoldenConfiguration();
            } finally {
                $this->goldenApplying = false;
            }
        }
    }

    public function refreshGoldenSeoEntitlement(): bool
    {
        $this->goldenSeoEntitled = app(SeoEntitlementProbe::class)->seoServiceEnabled();

        return $this->goldenSeoEntitled;
    }

    public function refreshGoldenPackageState(): void
    {
        $service = app(GoldenBootstrapService::class);
        $local = $service->inspectLocalPackage();
        $statePackage = null;
        try {
            $statePackage = app(\Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigurationSyncStateStore::class)->read()['package'] ?? null;
        } catch (\Throwable) {
        }

        if (is_array($local)) {
            $this->goldenPackageVersion = (string) $local['package_version'];
            $status = is_array($statePackage) ? (string) ($statePackage['status'] ?? 'pending_apply') : 'pending_apply';
            $this->goldenPackageStatus = $status === 'applied' ? 'applied' : 'pending_apply';
            $this->goldenDownloadMessage = $this->goldenPackageStatus === 'applied'
                ? 'Package đã áp dụng (v'.$this->goldenPackageVersion.').'
                : 'Package đã tải (v'.$this->goldenPackageVersion.') — chờ áp dụng.';
            return;
        }

        $this->goldenPackageStatus = 'none';
        $this->goldenPackageVersion = null;
        $this->goldenDownloadMessage = $this->isEnrolled
            ? 'Chưa tải Golden Package.'
            : 'Enrollment bắt buộc trước khi tải package.';
    }

    /**
     * Download/stage Golden package — enrollment credential only (no SEO entitlement).
     */
    public function downloadGoldenPackage(bool $quiet = false): void
    {
        $this->goldenError = null;
        $this->goldenDownloading = true;

        try {
            if (! $this->isEnrolled) {
                $this->goldenError = 'Chưa Enrollment — không thể tải Golden Package.';
                if (! $quiet) {
                    Notification::make()->title('Không thể tải package')->body($this->goldenError)->warning()->send();
                }

                return;
            }

            $res = app(GoldenBootstrapService::class)->downloadPackage();
            $status = (string) ($res['status'] ?? 'download_failed');

            if (in_array($status, ['downloaded', 'already_downloaded'], true)) {
                $this->refreshGoldenPackageState();
                if (! $quiet) {
                    Notification::make()
                        ->title($status === 'already_downloaded' ? 'Package đã sẵn sàng' : 'Tải Golden Package thành công')
                        ->body('Package đã lưu tại resources/golden-configuration. Chưa ghi vào database.')
                        ->success()
                        ->send();
                }
                $this->loadStep2Data();

                return;
            }

            if ($status === 'blocked_on_h1') {
                $this->goldenError = 'Thiếu credential Installation — hoàn tất Kích hoạt dịch vụ trước.';
            } else {
                $err = (string) ($res['error_code'] ?? $status);
                $this->goldenError = 'Tải Golden Package thất bại: '.$err;
                $this->goldenPackageStatus = 'error';
            }

            if (! $quiet) {
                Notification::make()->title('Lỗi tải package')->body($this->goldenError)->danger()->send();
            }
        } catch (\Throwable $e) {
            $this->goldenError = 'Lỗi tải package: '.$e->getMessage();
            $this->goldenPackageStatus = 'error';
            if (! $quiet) {
                Notification::make()->title('Lỗi ngoại lệ')->body($this->goldenError)->danger()->send();
            }
        } finally {
            $this->goldenDownloading = false;
        }
    }

    /**
     * Apply staged Golden package into DB (requires SEO entitlement).
     *
     * @return bool true when the wizard may leave Step 2
     */
    public function applyGoldenConfiguration(): bool
    {
        $this->goldenError = null;
        $this->goldenSuccess = false;
        $userId = (int) Auth::id();

        if (! $this->refreshGoldenSeoEntitlement()) {
            $this->goldenStatus = 'unlicensed';
            $this->goldenReason = $this->goldenPackageStatus === 'pending_apply'
                ? 'Golden đã tải, đang chờ quyền SEO để áp dụng.'
                : 'SEO chưa được cấp quyền — bỏ qua Apply và tiếp tục.';

            Notification::make()
                ->title('Bỏ qua áp dụng Golden')
                ->body($this->goldenReason)
                ->warning()
                ->send();

            return true;
        }

        try {
            $res = app(GoldenBootstrapService::class)->applyPackage(
                $userId,
                null,
                $this->overwriteGoldenConfiguration,
            );

            $status = $res['status'] ?? 'unknown';

            if ($status === 'bootstrapped') {
                $this->goldenSuccess = true;
                $this->loadStep2Data();

                Notification::make()
                    ->title('Áp dụng Cấu hình chuẩn thành công!')
                    ->body('Đã nạp thành công các Prompt và Quy trình chuẩn.')
                    ->success()
                    ->send();

                return true;
            }

            if ($status === 'existing_client') {
                $this->loadStep2Data();
                Notification::make()
                    ->title('Bảo toàn cấu hình hiện có')
                    ->body('Ghi đè đang tắt hoặc không được yêu cầu — cấu hình tùy chỉnh hiện có được giữ nguyên.')
                    ->info()
                    ->send();

                return true;
            }

            if (in_array($status, ['unlicensed_service', 'blocked_on_h1'], true)) {
                $this->goldenStatus = 'unlicensed';
                $this->goldenReason = $status === 'blocked_on_h1'
                    ? 'Chưa có credential enrollment.'
                    : 'Golden đã tải (nếu có), đang chờ quyền SEO để áp dụng.';
                Notification::make()->title('Bỏ qua áp dụng Golden')->body($this->goldenReason)->warning()->send();

                return true;
            }

            $errCode = $res['error_code'] ?? $status;
            $this->goldenError = 'Áp dụng cấu hình không thành công: '.$errCode.'. Package đã tải (nếu có) vẫn được giữ.';
            $this->refreshGoldenPackageState();

            Notification::make()
                ->title('Lỗi áp dụng cấu hình')
                ->body($this->goldenError)
                ->danger()
                ->send();

            return false;
        } catch (\Throwable $e) {
            $this->goldenError = 'Lỗi áp dụng cấu hình: '.$e->getMessage();
            $this->refreshGoldenPackageState();

            Notification::make()
                ->title('Lỗi ngoại lệ')
                ->body($this->goldenError)
                ->danger()
                ->send();

            return false;
        }
    }

    /**
     * Step 2 continue: ensure download; apply when SEO + (blank or overwrite consented).
     *
     * @return bool true when navigation to Step 3 is allowed
     */
    public function completeStep2BeforeAdvance(): bool
    {
        if ($this->goldenApplying || $this->goldenDownloading) {
            return false;
        }

        $this->refreshGoldenSeoEntitlement();
        $this->refreshGoldenPackageState();

        if ($this->isEnrolled && in_array($this->goldenPackageStatus, ['none', 'error'], true)) {
            $this->downloadGoldenPackage(quiet: true);
        }

        if (! $this->goldenSeoEntitled) {
            $this->goldenStatus = 'unlicensed';
            $this->goldenReason = $this->goldenPackageStatus === 'pending_apply'
                ? 'Golden đã tải. SEO chưa kích hoạt — bỏ qua Apply cấu hình SEO.'
                : 'SEO chưa kích hoạt — tiếp tục Quick Setup không Apply SEO.';
            $this->goldenError = null;

            return true;
        }

        if ($this->goldenStatus === 'in_sync' || $this->goldenSuccess || $this->goldenPackageStatus === 'applied') {
            return true;
        }

        if ($this->goldenStatus === 'customized' && ! $this->overwriteGoldenConfiguration) {
            return true;
        }

        if ($this->goldenStatus === 'blank') {
            $this->overwriteGoldenConfiguration = true;
        }

        $this->goldenApplying = true;
        try {
            return $this->applyGoldenConfiguration();
        } finally {
            $this->goldenApplying = false;
        }
    }

    // ==========================================
    // STEP 3 — KẾT NỐI AI
    // ==========================================

    public function loadStep3Data(): void
    {
        $userId = (int) Auth::id();

        $rows = ApiConnection::query()
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)->orWhere('is_global', true);
            })
            ->where('status', 'active')
            ->get();

        $list = [];
        foreach ($rows as $row) {
            if (ApiConnectionProviders::isAi((string) $row->provider)) {
                $list[] = [
                    'id' => $row->id,
                    'name' => (string) $row->name,
                    'provider' => (string) $row->provider,
                    'provider_label' => ApiConnectionProviders::label((string) $row->provider),
                    'masked_key' => filled($row->api_key) ? '••••••••••••••••' : 'Chưa có khóa',
                    'balance_status' => (string) ($row->balance_status ?? 'ok'),
                    'balance_text' => $row->formattedBalance(),
                    'is_global' => (bool) $row->is_global,
                ];
            }
        }

        $this->existingAiConnections = $list;

        if (empty($this->aiConnectionName)) {
            $this->aiConnectionName = 'Kết nối ' . ApiConnectionProviders::label($this->aiProvider);
        }
    }

    public function updatedAiProvider(string $val): void
    {
        $this->aiConnectionName = 'Kết nối ' . ApiConnectionProviders::label($val);
        $this->aiTestStatus = null;
        $this->aiTestDetails = null;
    }

    public function testAiConnection(): void
    {
        $this->aiTestStatus = null;
        $this->aiTestDetails = null;

        $key = trim($this->aiApiKey);
        if ($key === '') {
            $this->aiTestStatus = 'error';
            $this->aiTestDetails = 'Vui lòng nhập Khóa API trước khi kiểm tra.';
            return;
        }

        try {
            $tempConn = new ApiConnection([
                'user_id' => Auth::id(),
                'provider' => $this->aiProvider,
                'name' => $this->aiConnectionName,
                'api_key' => $key,
            ]);

            $tester = app(AiProviderConnectionTester::class);
            $stages = $tester->test($tempConn);

            $authStage = $stages['authentication'] ?? ['ok' => false];
            $modelStage = $stages['model_discovery'] ?? ['ok' => true];

            if (($authStage['ok'] ?? false) && ($modelStage['ok'] ?? false)) {
                $this->aiTestStatus = 'success';
                $detail = $modelStage['detail'] ?? 'Xác thực hợp lệ';
                $this->aiTestDetails = "Kết nối thành công! {$detail}.";

                Notification::make()
                    ->title('Kiểm tra kết nối AI thành công')
                    ->body($this->aiTestDetails)
                    ->success()
                    ->send();
            } else {
                $this->aiTestStatus = 'error';
                $err = ! empty($modelStage['detail']) ? $modelStage['detail'] : 'Xác thực API Key thất bại.';
                $this->aiTestDetails = "Kiểm tra thất bại: {$err}";

                Notification::make()
                    ->title('Kiểm tra kết nối AI thất bại')
                    ->body($this->aiTestDetails)
                    ->danger()
                    ->send();
            }
        } catch (\Throwable $e) {
            $this->aiTestStatus = 'error';
            $this->aiTestDetails = 'Lỗi kết nối: ' . $e->getMessage();

            Notification::make()
                ->title('Lỗi kết nối')
                ->body($this->aiTestDetails)
                ->danger()
                ->send();
        }
    }

    public function saveAiConnection(): void
    {
        $key = trim($this->aiApiKey);
        if ($key === '') {
            Notification::make()
                ->title('Chưa nhập Khóa API')
                ->body('Vui lòng nhập Khóa API để lưu kết nối.')
                ->warning()
                ->send();
            return;
        }

        try {
            ApiConnection::create([
                'user_id' => Auth::id(),
                'name' => $this->aiConnectionName ?: ('Kết nối ' . ApiConnectionProviders::label($this->aiProvider)),
                'provider' => $this->aiProvider,
                'api_key' => $key,
                'status' => 'active',
                'is_global' => true,
            ]);

            $this->aiApiKey = '';
            $this->aiTestStatus = null;
            $this->aiTestDetails = null;

            $this->loadAllStepData();

            Notification::make()
                ->title('Đã lưu kết nối AI')
                ->body('Kết nối API đã được lưu và mã hóa an toàn.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Lưu kết nối thất bại')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function deleteAiConnection(int $id): void
    {
        try {
            ApiConnection::query()->where('id', $id)->delete();
            $this->loadAllStepData();

            Notification::make()
                ->title('Đã xóa kết nối AI')
                ->success()
                ->send();
        } catch (\Throwable) {
            Notification::make()
                ->title('Không thể xóa kết nối')
                ->danger()
                ->send();
        }
    }

    /**
     * Site inventory for completion checklist (Installer Domain Anchor + ordinary sites).
     */
    public function loadSitesSummary(): void
    {
        $userId = (int) Auth::id();
        $this->isSeoLicensed = app(ServiceEntitlementGate::class)->allows('seo');

        $sites = Site::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get();

        $list = [];
        foreach ($sites as $site) {
            $list[] = [
                'id' => $site->id,
                'domain' => (string) $site->domain,
                'status' => (string) $site->status,
                'ssl' => (bool) $site->ssl,
                'is_anchor' => (bool) ($site->h1_is_anchor ?? false),
                'created_at' => $site->created_at ? $site->created_at->format('d/m/Y') : '—',
            ];
        }

        $this->existingSites = $list;
    }

    // ==========================================
    // STEP 4 — AAPANEL CRON (optional download)
    // ==========================================

    public function downloadAaPanelCronJson(AaPanelCronCatalog $catalog): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $json = $catalog->exportJsonString();

        return response()->streamDownload(static function () use ($json): void {
            echo $json;
        }, 'seo-ops-aapanel-cron.json', [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    // ==========================================
    // STEP 5 — HOÀN TẤT & TỔNG KẾT
    // ==========================================

    public function loadStep5Data(): void
    {
        $gate = app(ServiceEntitlementGate::class);
        $isEnrolled = app(InstallationStateRepository::class)->isEnrolled();
        $isSeo = $gate->allows('seo');

        $checklist = [];

        // 1. Phần mềm
        $checklist[] = [
            'key' => 'software',
            'title' => 'Cài đặt phần mềm SEO-OPS Client',
            'status' => 'complete',
            'badge' => 'emerald',
            'status_label' => 'Hoàn tất',
            'description' => 'Hệ điều hành ứng dụng và cơ sở dữ liệu đã sẵn sàng.',
            'link' => null,
            'link_label' => null,
        ];

        // 2. Kích hoạt bản quyền
        if ($isEnrolled) {
            $seoLabel = $isSeo ? 'SEO (Active)' : 'SEO (Inactive)';
            $checklist[] = [
                'key' => 'license',
                'title' => 'Bản quyền dịch vụ thương mại (OPS-SERVER)',
                'status' => 'complete',
                'badge' => 'emerald',
                'status_label' => 'Đã kích hoạt',
                'description' => "Đã liên kết thiết bị với OPS-SERVER. Dịch vụ: {$seoLabel}.",
                'link' => ServiceStatusOverview::getUrl(),
                'link_label' => 'Quản lý Dịch vụ',
            ];
        } else {
            $checklist[] = [
                'key' => 'license',
                'title' => 'Bản quyền dịch vụ thương mại (OPS-SERVER)',
                'status' => 'needs_attention',
                'badge' => 'amber',
                'status_label' => 'Chưa kích hoạt',
                'description' => 'Hệ thống đang hoạt động ở chế độ nội bộ. Cần nhập mã kích hoạt để sử dụng dịch vụ thương mại.',
                'link' => ServiceStatusOverview::getUrl(),
                'link_label' => 'Xem chi tiết',
            ];
        }

        // 3. Cấu hình chuẩn Golden
        if ($this->goldenStatus === 'in_sync' || $this->goldenSuccess) {
            $checklist[] = [
                'key' => 'golden',
                'title' => 'Cấu hình chuẩn SEO-OPS (Golden Configuration)',
                'status' => 'complete',
                'badge' => 'emerald',
                'status_label' => 'Đã nạp chuẩn',
                'description' => "Đang sử dụng cấu hình chuẩn ({$this->goldenCounts['prompts']} prompts, {$this->goldenCounts['workflows']} workflows).",
                'link' => url('/admin/settings/general'),
                'link_label' => 'Cài đặt SEO',
            ];
        } elseif ($this->goldenStatus === 'customized') {
            $checklist[] = [
                'key' => 'golden',
                'title' => 'Cấu hình SEO & Quy trình làm việc',
                'status' => 'complete',
                'badge' => 'blue',
                'status_label' => 'Tùy chỉnh riêng',
                'description' => "Đang sử dụng cấu hình tùy chỉnh riêng ({$this->goldenCounts['prompts']} prompts, {$this->goldenCounts['workflows']} workflows).",
                'link' => url('/admin/settings/general'),
                'link_label' => 'Cài đặt SEO',
            ];
        } elseif ($this->goldenStatus === 'unlicensed' || ! $this->goldenSeoEntitled || ! $isSeo) {
            $checklist[] = [
                'key' => 'golden',
                'title' => 'Cấu hình chuẩn SEO-OPS (Golden Configuration)',
                'status' => 'pending',
                'badge' => 'amber',
                'status_label' => 'Chờ bản quyền SEO',
                'description' => 'Đã bỏ qua nạp Golden vì SEO chưa được cấp quyền. Đồng bộ lại sau khi OPS-SERVER cấp entitlement.',
                'link' => ServiceStatusOverview::getUrl(),
                'link_label' => 'Quản lý Dịch vụ',
            ];
        } else {
            $checklist[] = [
                'key' => 'golden',
                'title' => 'Cấu hình chuẩn SEO-OPS (Golden Configuration)',
                'status' => 'optional',
                'badge' => 'amber',
                'status_label' => 'Chưa nạp',
                'description' => 'Chưa áp dụng cấu hình chuẩn. Bạn có thể nạp ở Bước 2 bất kỳ lúc nào.',
                'link' => url('/admin/settings/general'),
                'link_label' => 'Cài đặt SEO',
            ];
        }

        // 4. Kết nối AI
        $aiCount = count($this->existingAiConnections);
        if ($aiCount > 0) {
            $checklist[] = [
                'key' => 'ai',
                'title' => 'Kết nối dịch vụ AI',
                'status' => 'complete',
                'badge' => 'emerald',
                'status_label' => "Đã kết nối ({$aiCount})",
                'description' => 'Đã cấu hình ít nhất một nhà cung cấp AI khả dụng.',
                'link' => url('/admin/settings/api'),
                'link_label' => 'Cài đặt API',
            ];
        } else {
            $checklist[] = [
                'key' => 'ai',
                'title' => 'Kết nối dịch vụ AI',
                'status' => 'optional',
                'badge' => 'amber',
                'status_label' => 'Tùy chọn',
                'description' => 'Chưa cấu hình Khóa API AI. Bạn có thể thêm khóa bất kỳ lúc nào để tạo bài viết tự động.',
                'link' => url('/admin/settings/api'),
                'link_label' => 'Thêm API Key',
            ];
        }

        // 5. Website (Domain Anchor from Fresh Installer + ordinary sites)
        $sitesCount = count($this->existingSites);
        $anchorDomain = null;
        foreach ($this->existingSites as $siteRow) {
            if (! empty($siteRow['is_anchor'])) {
                $anchorDomain = (string) $siteRow['domain'];
                break;
            }
        }
        if ($sitesCount > 0) {
            $checklist[] = [
                'key' => 'sites',
                'title' => 'Quản lý Website',
                'status' => 'complete',
                'badge' => 'emerald',
                'status_label' => "Đã có {$sitesCount} website",
                'description' => $anchorDomain
                    ? "Website chính (Domain Anchor): {$anchorDomain}. Quản lý thêm website trong menu Quản lý Website."
                    : 'Đã có website trong hệ thống (đăng ký từ Fresh Installer hoặc Quản lý Website).',
                'link' => url('/admin/sites'),
                'link_label' => 'Danh sách Website',
            ];
        } elseif (! $isSeo) {
            $checklist[] = [
                'key' => 'sites',
                'title' => 'Quản lý Website',
                'status' => 'unavailable',
                'badge' => 'gray',
                'status_label' => 'Chưa khả dụng',
                'description' => 'Yêu cầu bản quyền SEO để quản lý website bổ sung. Website chính được đăng ký ở Fresh Installer.',
                'link' => url('/admin/sites'),
                'link_label' => 'Mở Quản lý Website',
            ];
        } else {
            $checklist[] = [
                'key' => 'sites',
                'title' => 'Quản lý Website',
                'status' => 'optional',
                'badge' => 'gray',
                'status_label' => 'Chưa có website',
                'description' => 'Website chính nên được đăng ký ở Fresh Installer. Thêm website vệ tinh trong menu Quản lý Website.',
                'link' => url('/admin/sites'),
                'link_label' => 'Thêm Website',
            ];
        }

        $this->checklist = $checklist;
    }

    public function finishQuickSetup(): void
    {
        WpOption::set('quick_setup_completed_at', now()->toIso8601String());

        Notification::make()
            ->title('Đã hoàn tất thiết lập ban đầu')
            ->body('Chào mừng bạn đến với SEO-OPS. Bạn có thể mở lại trang Thiết lập nhanh bất kỳ lúc nào.')
            ->success()
            ->send();

        $this->redirect(Dashboard::getUrl());
    }

    // ==========================================
    // NAVIGATION CONTROLS
    // ==========================================

    public function goToStep(int $step): void
    {
        if ($step > self::MAX_STEP) {
            $step = self::MAX_STEP;
        }
        if ($step < 1 || $step > self::MAX_STEP) {
            return;
        }

        if ($step > 1 && ! $this->canContinuePastCommercialGate()) {
            $this->currentStep = 1;
            $this->commercialGateMessage = self::COMMERCIAL_GATE_MESSAGE;
            $this->loadStep1Data();
            Notification::make()
                ->title('Chưa thể tiếp tục')
                ->body(self::COMMERCIAL_GATE_MESSAGE)
                ->warning()
                ->send();

            return;
        }

        $this->currentStep = $step;
        $this->loadAllStepData();
        if ($step === 2) {
            $this->runAutomaticGoldenLifecycle();
        }
    }

    public function nextStep(): void
    {
        if ($this->currentStep === 1) {
            if ($this->reconcileRunning) {
                return;
            }
            if (! $this->canContinuePastCommercialGate()) {
                $this->commercialGateMessage = self::COMMERCIAL_GATE_MESSAGE;
                Notification::make()
                    ->title('Chưa thể tiếp tục')
                    ->body(self::COMMERCIAL_GATE_MESSAGE)
                    ->warning()
                    ->send();

                return;
            }
            $this->goToStep(2);

            return;
        }

        if ($this->currentStep === 2 && ! $this->completeStep2BeforeAdvance()) {
            return;
        }

        if ($this->currentStep < self::MAX_STEP) {
            $this->goToStep($this->currentStep + 1);
        }
    }

    public function prevStep(): void
    {
        if ($this->currentStep > 1) {
            $this->goToStep($this->currentStep - 1);
        }
    }
}

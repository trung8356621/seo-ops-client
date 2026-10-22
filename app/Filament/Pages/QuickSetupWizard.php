<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\ApiConnection;
use App\Models\H1Installation;
use App\Models\Site;
use App\Models\User;
use App\Models\WpOption;
use App\Services\H1\Enforcement\ServiceEntitlementGate;
use App\Services\H1\Enrollment\EnrollmentConsumer;
use App\Services\H1\Exceptions\H1EnrollmentException;
use App\Services\H1\Persistence\InstallationStateRepository;
use App\Services\H1\Reconcile\LicenseReconcileService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\AiProviderConnectionTester;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\BlankInstallationDetector;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\ConfigurationSyncCoordinator;
use Omnichannel\Addons\Seo\Services\ConfigurationSync\GoldenBootstrapService;

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
    public string $enrollmentToken = '';
    public ?string $enrollmentError = null;
    public ?string $enrollmentErrorCode = null;
    public bool $enrollmentSuccess = false;
    public bool $isEnrolled = false;
    public ?array $installationDetails = null;

    // STEP 2 Properties
    public bool $isSeoLicensed = false;
    public string $goldenStatus = 'blank'; // blank, in_sync, customized, unlicensed, error
    public ?string $goldenReason = null;
    public array $goldenCounts = ['prompts' => 0, 'workflows' => 0, 'bindings' => 0];
    public ?string $goldenError = null;
    public bool $goldenSuccess = false;

    // STEP 3 Properties
    public string $aiProvider = 'gemini';
    public string $aiConnectionName = '';
    public string $aiApiKey = '';
    public ?string $aiTestStatus = null; // success, error
    public ?string $aiTestDetails = null;
    public array $existingAiConnections = [];

    // STEP 4 Properties
    public string $newSiteDomain = '';
    public bool $newSiteSsl = true;
    public array $existingSites = [];
    public ?string $siteError = null;

    // STEP 5 Properties
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
        if ($requestedStep >= 1 && $requestedStep <= 5) {
            $this->currentStep = $requestedStep;
        }

        $this->loadAllStepData();
    }

    protected function getViewData(): array
    {
        return [
            'currentStep' => $this->currentStep,
            'isEnrolled' => $this->isEnrolled,
            'installationDetails' => $this->installationDetails,
            'enrollmentToken' => $this->enrollmentToken,
            'enrollmentError' => $this->enrollmentError,
            'enrollmentSuccess' => $this->enrollmentSuccess,
            'isSeoLicensed' => $this->isSeoLicensed,
            'goldenStatus' => $this->goldenStatus,
            'goldenReason' => $this->goldenReason,
            'goldenCounts' => $this->goldenCounts,
            'goldenError' => $this->goldenError,
            'goldenSuccess' => $this->goldenSuccess,
            'aiProvider' => $this->aiProvider,
            'aiConnectionName' => $this->aiConnectionName,
            'aiApiKey' => $this->aiApiKey,
            'aiTestStatus' => $this->aiTestStatus,
            'aiTestDetails' => $this->aiTestDetails,
            'existingAiConnections' => $this->existingAiConnections,
            'newSiteDomain' => $this->newSiteDomain,
            'newSiteSsl' => $this->newSiteSsl,
            'existingSites' => $this->existingSites,
            'siteError' => $this->siteError,
            'checklist' => $this->checklist,
        ];
    }

    public function loadAllStepData(): void
    {
        $this->loadStep1Data();
        $this->loadStep2Data();
        $this->loadStep3Data();
        $this->loadStep4Data();
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

        if ($installation instanceof H1Installation && $this->isEnrolled) {
            $cred = $installations->activeCredential($installation->installation_id);
            $services = is_array($installation->verified_snapshot['services'] ?? null)
                ? $installation->verified_snapshot['services']
                : [];

            $seo = $services['seo'] ?? [];
            $seeding = $services['seeding'] ?? [];

            $this->installationDetails = [
                'installation_id' => $installation->installation_id,
                'control_server_url' => (string) ($installation->control_server_url ?: config('h1-license.base_url', '—')),
                'credential_version' => $installation->credential_version ? "v{$installation->credential_version}" : ($cred ? "v{$cred->version}" : 'v1'),
                'last_reconciled' => $installation->validated_at ? $installation->validated_at->format('d/m/Y H:i:s') : 'Chưa có',
                'seo_active' => ! empty($seo['enabled']) && empty($seo['revoked_at']),
                'seo_quota' => isset($seo['quota']) ? "{$seo['quota']} domains" : 'Không giới hạn',
                'seo_valid_until' => ! empty($seo['valid_until']) ? (string) $seo['valid_until'] : '—',
                'seeding_active' => ! empty($seeding['enabled']) && empty($seeding['revoked_at']),
                'seeding_valid_until' => ! empty($seeding['valid_until']) ? (string) $seeding['valid_until'] : '—',
            ];
        } else {
            $this->installationDetails = null;
        }
    }

    public function activateEnrollment(): void
    {
        $this->enrollmentError = null;
        $this->enrollmentErrorCode = null;
        $this->enrollmentSuccess = false;

        $token = trim($this->enrollmentToken);
        if ($token === '') {
            $this->enrollmentError = 'Vui lòng nhập mã kích hoạt.';
            return;
        }

        if (! str_starts_with($token, 'tok_')) {
            $this->enrollmentError = 'Mã kích hoạt không đúng định dạng (phải bắt đầu bằng "tok_").';
            return;
        }

        try {
            $consumer = app(EnrollmentConsumer::class);
            $result = $consumer->enroll($token);

            $this->enrollmentSuccess = true;
            $this->enrollmentToken = '';
            $this->loadAllStepData();

            Notification::make()
                ->title('Kích hoạt dịch vụ thành công!')
                ->body('Installation ID: ' . ($result['installation_id'] ?? ''))
                ->success()
                ->send();
        } catch (H1EnrollmentException $e) {
            $this->enrollmentErrorCode = $e->errorCode;
            $this->enrollmentError = match ($e->errorCode) {
                'invalid_receipt' => 'Mã kích hoạt không hợp lệ (phải bắt đầu bằng tok_).',
                'token_expired' => 'Mã kích hoạt đã hết hạn. Vui lòng tạo mã mới trên OPS-SERVER.',
                'token_used' => 'Mã kích hoạt này đã được sử dụng trước đó.',
                'already_enrolled' => 'Thiết bị này đã được liên kết với một ID cài đặt khác.',
                default => 'Lỗi kích hoạt [' . $e->errorCode . ']: ' . $e->getMessage(),
            };

            Notification::make()
                ->title('Kích hoạt không thành công')
                ->body($this->enrollmentError)
                ->danger()
                ->send();
        } catch (\Throwable $e) {
            $this->enrollmentError = 'Không thể kết nối đến máy chủ OPS-SERVER: ' . $e->getMessage();

            Notification::make()
                ->title('Lỗi kết nối')
                ->body($this->enrollmentError)
                ->danger()
                ->send();
        }
    }

    public function reconcileLicense(): void
    {
        try {
            $reconcile = app(LicenseReconcileService::class);
            $result = $reconcile->reconcile();

            $this->loadAllStepData();

            if ($result['offline'] ?? false) {
                Notification::make()
                    ->title('OPS-SERVER Ngoại tuyến')
                    ->body('Máy chủ OPS-SERVER tạm thời ngoại tuyến. Đang dùng bản quyền xác minh gần nhất.')
                    ->warning()
                    ->send();
            } else {
                Notification::make()
                    ->title('Đã đồng bộ bản quyền thành công')
                    ->body('Bản quyền đã được xác minh lại với OPS-SERVER.')
                    ->success()
                    ->send();
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Đồng bộ thất bại')
                ->body('Lỗi: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    // ==========================================
    // STEP 2 — CẤU HÌNH MẶC ĐỊNH (GOLDEN CONFIG)
    // ==========================================

    public function loadStep2Data(): void
    {
        $userId = (int) Auth::id();
        $gate = app(ServiceEntitlementGate::class);
        $this->isSeoLicensed = $gate->allows('seo');

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
        } catch (\Throwable) {}

        $this->goldenCounts = [
            'prompts' => $promptsCount,
            'workflows' => $workflowsCount,
            'bindings' => $bindingsCount,
        ];

        if (! $this->isSeoLicensed) {
            $this->goldenStatus = 'unlicensed';
            $this->goldenReason = 'Dịch vụ SEO chưa được kích hoạt bản quyền.';
            return;
        }

        try {
            $blankAssessor = app(BlankInstallationDetector::class);
            $assessment = $blankAssessor->assess($userId);

            if ($assessment['blank'] === true) {
                $this->goldenStatus = 'blank';
                $this->goldenReason = 'Chưa nạp cấu hình chuẩn. Sẵn sàng nạp.';
            } else {
                // Client has configuration, compare against baseline
                $coordinator = app(ConfigurationSyncCoordinator::class);
                $comparison = $coordinator->compare($userId);
                $outcome = $comparison['outcome'] ?? 'customized';

                if ($outcome === 'in_sync') {
                    $this->goldenStatus = 'in_sync';
                    $this->goldenReason = 'Cấu hình hiện tại khớp hoàn toàn với cấu hình chuẩn (Golden Configuration).';
                } else {
                    $this->goldenStatus = 'customized';
                    $this->goldenReason = 'Hệ thống phát hiện cấu hình đã được tùy chỉnh. Dữ liệu của bạn được bảo toàn.';
                }
            }
        } catch (\Throwable $e) {
            $this->goldenStatus = 'error';
            $this->goldenReason = 'Không thể kiểm tra cấu hình: ' . $e->getMessage();
        }
    }

    public function applyGoldenConfiguration(): void
    {
        $this->goldenError = null;
        $this->goldenSuccess = false;
        $userId = (int) Auth::id();

        if (! $this->isSeoLicensed) {
            $this->goldenError = 'Cần kích hoạt bản quyền SEO trước khi nạp cấu hình chuẩn.';
            return;
        }

        try {
            $bootstrapService = app(GoldenBootstrapService::class);
            $res = $bootstrapService->bootstrap($userId);

            $status = $res['status'] ?? 'unknown';

            if ($status === 'bootstrapped') {
                $this->goldenSuccess = true;
                $this->loadAllStepData();

                Notification::make()
                    ->title('Áp dụng Cấu hình chuẩn thành công!')
                    ->body('Đã nạp thành công các Prompt và Quy trình chuẩn.')
                    ->success()
                    ->send();
            } elseif ($status === 'existing_client') {
                $this->loadAllStepData();
                Notification::make()
                    ->title('Bảo toàn cấu hình hiện có')
                    ->body('Hệ thống phát hiện đã có dữ liệu cấu hình, giữ nguyên không ghi đè.')
                    ->info()
                    ->send();
            } else {
                $errCode = $res['error_code'] ?? $status;
                $this->goldenError = 'Nạp cấu hình không thành công: ' . $errCode;

                Notification::make()
                    ->title('Lỗi nạp cấu hình')
                    ->body($this->goldenError)
                    ->danger()
                    ->send();
            }
        } catch (\Throwable $e) {
            $this->goldenError = 'Lỗi nạp cấu hình: ' . $e->getMessage();

            Notification::make()
                ->title('Lỗi ngoại lệ')
                ->body($this->goldenError)
                ->danger()
                ->send();
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

    // ==========================================
    // STEP 4 — WEBSITE ĐẦU TIÊN
    // ==========================================

    public function loadStep4Data(): void
    {
        $userId = (int) Auth::id();
        $gate = app(ServiceEntitlementGate::class);
        $this->isSeoLicensed = $gate->allows('seo');

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
                'created_at' => $site->created_at ? $site->created_at->format('d/m/Y') : '—',
            ];
        }

        $this->existingSites = $list;
    }

    public function createSite(): void
    {
        $this->siteError = null;

        if (! $this->isSeoLicensed) {
            $this->siteError = 'Dịch vụ SEO chưa được cấp phép. Không thể thêm website.';
            Notification::make()
                ->title('Dịch vụ chưa được cấp phép')
                ->body($this->siteError)
                ->warning()
                ->send();
            return;
        }

        $domain = strtolower(trim($this->newSiteDomain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');

        if ($domain === '') {
            $this->siteError = 'Vui lòng nhập tên miền website.';
            return;
        }

        if (! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/i', $domain)) {
            $this->siteError = 'Tên miền không đúng định dạng (Ví dụ: example.com).';
            return;
        }

        $userId = (int) Auth::id();
        $exists = Site::query()->where('user_id', $userId)->where('domain', $domain)->exists();
        if ($exists) {
            $this->siteError = 'Website này đã tồn tại trong danh sách của bạn.';
            return;
        }

        try {
            // New sites are kept inactive initially
            Site::create([
                'user_id' => $userId,
                'domain' => $domain,
                'ssl' => $this->newSiteSsl,
                'status' => 'inactive',
            ]);

            $this->newSiteDomain = '';
            $this->loadAllStepData();

            Notification::make()
                ->title('Đã thêm website thành công')
                ->body("Website {$domain} đã được tạo với trạng thái Chưa kích hoạt.")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            $this->siteError = 'Lỗi tạo website: ' . $e->getMessage();

            Notification::make()
                ->title('Lỗi thêm website')
                ->body($this->siteError)
                ->danger()
                ->send();
        }
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
        } elseif (! $isSeo) {
            $checklist[] = [
                'key' => 'golden',
                'title' => 'Cấu hình chuẩn SEO-OPS (Golden Configuration)',
                'status' => 'unavailable',
                'badge' => 'gray',
                'status_label' => 'Chưa khả dụng',
                'description' => 'Yêu cầu kích hoạt bản quyền dịch vụ SEO để tải cấu hình chuẩn.',
                'link' => null,
                'link_label' => null,
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

        // 5. Website
        $sitesCount = count($this->existingSites);
        if ($sitesCount > 0) {
            $checklist[] = [
                'key' => 'sites',
                'title' => 'Quản lý Website',
                'status' => 'complete',
                'badge' => 'emerald',
                'status_label' => "Đã có {$sitesCount} website",
                'description' => 'Đã tạo website đầu tiên trong hệ thống.',
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
                'description' => 'Yêu cầu bản quyền SEO để quản lý website.',
                'link' => null,
                'link_label' => null,
            ];
        } else {
            $checklist[] = [
                'key' => 'sites',
                'title' => 'Quản lý Website',
                'status' => 'optional',
                'badge' => 'gray',
                'status_label' => 'Chưa có website',
                'description' => 'Bạn có thể thêm website ở Bước 4 hoặc trực tiếp trong menu Quản lý Website.',
                'link' => url('/admin/sites'),
                'link_label' => 'Thêm Website',
            ];
        }

        // 6. Tác vụ nền Cron
        $checklist[] = [
            'key' => 'cron',
            'title' => 'Tác vụ nền định kỳ (aaPanel Cron)',
            'status' => 'info',
            'badge' => 'blue',
            'status_label' => 'Hướng dẫn',
            'description' => 'Thiết lập Cronjob trên aaPanel để hệ thống tự động xử lý hàng đợi và tác vụ định kỳ.',
            'link' => null,
            'link_label' => null,
        ];

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
        if ($step >= 1 && $step <= 5) {
            $this->currentStep = $step;
            $this->loadAllStepData();
        }
    }

    public function nextStep(): void
    {
        if ($this->currentStep < 5) {
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

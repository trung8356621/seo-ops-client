<x-filament-panels::page>
    <div class="max-w-4xl mx-auto space-y-6">

        {{-- STEPPER HEADER --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 sm:p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
            @php
                $steps = [
                    1 => ['short' => 'Kích hoạt', 'full' => 'Kích hoạt dịch vụ', 'desc' => 'Bản quyền OPS'],
                    2 => ['short' => 'Cấu hình', 'full' => 'Cấu hình mặc định', 'desc' => 'Golden Config'],
                    3 => ['short' => 'Kết nối AI', 'full' => 'Kết nối AI', 'desc' => 'API Providers'],
                    4 => ['short' => 'Cron', 'full' => 'Tác vụ nền định kỳ', 'desc' => 'aaPanel Cron'],
                    5 => ['short' => 'Hoàn tất', 'full' => 'Hoàn tất & Tổng kết', 'desc' => 'Sẵn sàng'],
                ];
                $maxStep = \App\Filament\Pages\QuickSetupWizard::MAX_STEP;
            @endphp

            {{-- MOBILE VIEW (< 640px: 320, 375, 390px) --}}
            <div class="block sm:hidden space-y-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-primary-600 text-xs font-bold text-white dark:bg-primary-500">
                            {{ $currentStep }}
                        </span>
                        <div class="text-xs font-bold text-gray-900 dark:text-white">
                            {{ $steps[$currentStep]['full'] }}
                        </div>
                    </div>
                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold bg-primary-50 text-primary-700 dark:bg-primary-950/50 dark:text-primary-300">
                        Bước {{ $currentStep }}/{{ $maxStep }}
                    </span>
                </div>

                {{-- Segment interactive tap bar --}}
                <div class="flex items-center gap-1.5 w-full">
                    @for ($i = 1; $i <= $maxStep; $i++)
                        @php
                            $segActive = $currentStep === $i;
                            $segPassed = $currentStep > $i;
                        @endphp
                        <button
                            type="button"
                            wire:click="goToStep({{ $i }})"
                            class="flex-1 h-2 rounded-full transition-all focus:outline-none
                                @if ($segPassed)
                                    bg-emerald-500 hover:bg-emerald-600
                                @elseif ($segActive)
                                    bg-primary-600 dark:bg-primary-500 ring-2 ring-primary-500/30
                                @else
                                    bg-gray-200 dark:bg-gray-800 hover:bg-gray-300 dark:hover:bg-gray-700
                                @endif
                            "
                            title="Bước {{ $i }}: {{ $steps[$i]['full'] }}"
                        ></button>
                    @endfor
                </div>
            </div>

            {{-- TABLET & DESKTOP VIEW (>= 640px: 768px, 1440px) --}}
            <div class="hidden sm:block">
                <div class="relative flex items-center justify-between w-full">
                    {{-- Connecting track behind circles --}}
                    <div class="absolute left-0 top-4 w-full h-0.5 bg-gray-200 dark:bg-gray-800 -z-0"></div>

                    @foreach ($steps as $num => $step)
                        @php
                            $isActive = $currentStep === $num;
                            $isPassed = $currentStep > $num;
                        @endphp

                        <button
                            type="button"
                            wire:click="goToStep({{ $num }})"
                            class="group relative flex flex-1 flex-col items-center text-center focus:outline-none z-10"
                        >
                            {{-- Step circle --}}
                            <div class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold transition-all shadow-sm ring-4 ring-white dark:ring-gray-900
                                @if ($isActive)
                                    bg-primary-600 text-white ring-primary-500/20 dark:bg-primary-500
                                @elseif ($isPassed)
                                    bg-emerald-500 text-white dark:bg-emerald-600
                                @else
                                    bg-gray-100 text-gray-500 group-hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-400 dark:group-hover:bg-gray-700
                                @endif
                            ">
                                @if ($isPassed)
                                    <x-filament::icon icon="heroicon-m-check" class="h-4 w-4" />
                                @else
                                    {{ $num }}
                                @endif
                            </div>

                            {{-- Step label --}}
                            <div class="mt-2 flex flex-col items-center">
                                <span class="text-xs transition-colors
                                    @if ($isActive)
                                        font-bold text-primary-600 dark:text-primary-400
                                    @elseif ($isPassed)
                                        font-medium text-gray-800 dark:text-gray-200
                                    @else
                                        font-medium text-gray-400 dark:text-gray-500
                                    @endif
                                ">
                                    {{ $step['full'] }}
                                </span>
                                <span class="text-[10px] text-gray-400 dark:text-gray-500 hidden md:inline">
                                    {{ $step['desc'] }}
                                </span>
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- STEP 1: KÍCH HOẠT DỊCH VỤ --}}
        @if ($currentStep === 1)
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900 space-y-6">
                <div>
                    <h3 class="text-lg font-bold text-gray-950 dark:text-white flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-key" class="h-5 w-5 text-primary-500" />
                        Bước 1: Kích hoạt dịch vụ thương mại
                    </h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                        Liên kết phần mềm với máy chủ trung tâm OPS-SERVER để cấp quyền sử dụng các dịch vụ SEO và Tự động hóa.
                    </p>
                </div>

                @if ($isEnrolled && $installationDetails)
                    @php
                        $isRecovery = in_array($connectionState ?? '', ['INSTALLATION_REVOKED', 'INSTALLATION_NOT_FOUND'], true);
                        $isConnError = ($connectionState ?? '') === 'CONNECTION_ERROR';
                        $snapshotStale = $isRecovery || ! empty($installationDetails['snapshot_stale']);
                    @endphp
                    {{-- Enrolled local key — auto-reconcile once; Recovery when REVOKED --}}
                    <div
                        wire:init="maybeAutoReconcileOnStep1"
                        @class([
                            'rounded-lg border p-4 space-y-4',
                            'border-rose-500/40 bg-rose-50/60 dark:border-rose-500/30 dark:bg-rose-950/25' => $isRecovery,
                            'border-amber-500/40 bg-amber-50/50 dark:border-amber-500/25 dark:bg-amber-950/20' => $isConnError && ! $isRecovery,
                            'border-emerald-500/30 bg-emerald-50/50 dark:border-emerald-500/20 dark:bg-emerald-950/20' => ! $isRecovery && ! $isConnError,
                        ])
                    >
                        @if ($isRecovery)
                            <div class="flex items-start gap-2 text-rose-800 dark:text-rose-300 font-semibold text-sm">
                                <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-5 w-5 shrink-0" />
                                <div>
                                    <div>
                                        {{ ($connectionState ?? '') === 'INSTALLATION_NOT_FOUND'
                                            ? 'Installation không tồn tại trên OPS-SERVER — Recovery Mode'
                                            : 'Installation đã bị thu hồi — Recovery Mode' }}
                                    </div>
                                    @if (! empty($connectionReason))
                                        <div class="mt-1 text-xs font-normal opacity-90">Lý do: {{ $connectionReason }}</div>
                                    @endif
                                </div>
                            </div>
                        @elseif ($isConnError)
                            <div class="flex items-center gap-2 text-amber-800 dark:text-amber-300 font-semibold text-sm">
                                <x-filament::icon icon="heroicon-m-signal-slash" class="h-5 w-5" />
                                <span>Lỗi kết nối OPS-SERVER — key cục bộ được giữ nguyên</span>
                            </div>
                        @else
                            <div class="flex items-center gap-2 text-emerald-700 dark:text-emerald-400 font-semibold text-sm">
                                <x-filament::icon icon="heroicon-m-check-circle" class="h-5 w-5" />
                                <span>Thiết bị đã được kích hoạt với OPS-SERVER</span>
                            </div>
                        @endif

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm text-gray-600 dark:text-gray-300">
                            <div>
                                <span class="font-medium text-gray-500">Mã thiết bị:</span>
                                <div class="font-mono text-gray-900 dark:text-white truncate">{{ $installationDetails['installation_id'] }}</div>
                            </div>
                            <div>
                                <span class="font-medium text-gray-500">Đồng bộ lần cuối:</span>
                                <div class="font-medium text-gray-900 dark:text-white">{{ $installationDetails['last_reconciled'] }}</div>
                            </div>
                        </div>

                        @if ($reconcileRunning)
                            <div class="flex items-center gap-3 rounded-lg border border-sky-400/40 bg-sky-50 p-3 text-sm text-sky-900 dark:border-sky-500/30 dark:bg-sky-950/30 dark:text-sky-200">
                                <x-filament::loading-indicator class="h-5 w-5" />
                                <span>Đang đồng bộ quyền sử dụng với OPS-SERVER…</span>
                            </div>
                        @endif

                        @if ($isConnError && $reconcileError)
                            <div class="rounded-lg border border-amber-500/40 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-950/30 dark:text-amber-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <span>{{ $reconcileError }}</span>
                                <x-filament::button
                                    wire:click="reconcileLicense"
                                    color="warning"
                                    size="sm"
                                    icon="heroicon-m-arrow-path"
                                    wire:loading.attr="disabled"
                                    wire:target="reconcileLicense,maybeAutoReconcileOnStep1"
                                    :disabled="$reconcileRunning"
                                >
                                    Thử lại
                                </x-filament::button>
                            </div>
                        @endif

                        {{-- Commercial entitlements only — never catalog placeholders / Core as Disabled --}}
                        <div class="space-y-2 {{ $reconcileRunning ? 'opacity-60' : '' }}">
                            <div class="text-sm font-semibold text-gray-900 dark:text-white">
                                {{ $snapshotStale ? 'Snapshot quyền cũ (không còn hiệu lực)' : 'Dịch vụ thương mại' }}
                            </div>
                            @if ($snapshotStale)
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    Thông tin dưới đây chỉ là bản ghi cục bộ trước khi thu hồi — không phải entitlement hiện tại.
                                </p>
                            @endif
                            @if (count($commercialServices) === 0)
                                <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-5 text-sm text-gray-600 dark:border-white/10 dark:bg-gray-800/40 dark:text-gray-300">
                                    Chưa có entitlement thương mại nào được cấp cho Installation này.
                                    Agent/Core không thuộc danh sách thương mại. Cấp quyền qua Order trên OPS-SERVER rồi Đồng bộ H1.
                                </div>
                            @else
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    @foreach ($commercialServices as $svc)
                                        @php
                                            $status = $snapshotStale ? 'revoked' : (string) ($svc['status'] ?? (($svc['enabled'] ?? false) ? 'active' : 'suspended'));
                                            $liveEnabled = ! $snapshotStale && $status === 'active';
                                        @endphp
                                        <div class="rounded-xl border p-4
                                            {{ $liveEnabled
                                                ? 'border-emerald-400/50 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-950/30'
                                                : 'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-gray-800/50' }}
                                        ">
                                            <div class="text-base font-bold text-gray-950 dark:text-white">{{ $svc['label'] }}</div>
                                            <div class="mt-2">
                                                @if ($status === 'active')
                                                    <span class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-semibold bg-emerald-600 text-white dark:bg-emerald-500">
                                                        Active
                                                    </span>
                                                @elseif ($status === 'suspended')
                                                    <span class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-semibold bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200">
                                                        Suspended
                                                    </span>
                                                @elseif ($status === 'expired')
                                                    <span class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-semibold bg-orange-100 text-orange-900 dark:bg-orange-900/40 dark:text-orange-200">
                                                        Expired
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-semibold bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200">
                                                        Revoked
                                                    </span>
                                                @endif
                                            </div>
                                            @if (! empty($svc['quota_label']))
                                                <div class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                                                    {{ $snapshotStale ? 'Quota (snapshot cũ):' : 'Quota:' }} {{ $svc['quota_label'] }}
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        @if ($isRecovery)
                            <div class="border-t border-rose-200/60 pt-4 dark:border-rose-800/40 space-y-3">
                                <div class="text-sm font-semibold text-gray-900 dark:text-white">Kết nối lại OPS-SERVER</div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    Nhập Enrollment Token của Installation mới. Không chạy lại Installer — website/article/project được giữ nguyên.
                                </p>
                                @if ($enrollmentError)
                                    <div class="rounded-lg border border-rose-500/30 bg-rose-50 p-3 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-950/30 dark:text-rose-400">
                                        {{ $enrollmentError }}
                                    </div>
                                @endif
                                <div class="flex flex-col sm:flex-row gap-2 items-stretch sm:items-end">
                                    <div class="flex-1 space-y-1">
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300" for="qs-reconnect-token">
                                            Enrollment Token mới
                                        </label>
                                        <input
                                            id="qs-reconnect-token"
                                            type="text"
                                            wire:model="enrollmentToken"
                                            wire:loading.attr="disabled"
                                            wire:target="activateEnrollment,prepareReconnect"
                                            class="w-full rounded-lg border-gray-300 font-mono text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                            placeholder="tok_…"
                                            autocomplete="off"
                                        />
                                    </div>
                                    @if ($showReconnectConfirm)
                                        <x-filament::button
                                            wire:click="activateEnrollment"
                                            color="success"
                                            size="sm"
                                            icon="heroicon-m-check"
                                            wire:loading.attr="disabled"
                                            wire:target="activateEnrollment"
                                        >
                                            Xác nhận kết nối lại
                                        </x-filament::button>
                                        <x-filament::button
                                            wire:click="cancelReconnectConfirm"
                                            color="gray"
                                            size="sm"
                                        >
                                            Hủy
                                        </x-filament::button>
                                    @else
                                        <x-filament::button
                                            wire:click="prepareReconnect"
                                            color="warning"
                                            size="sm"
                                            icon="heroicon-m-arrow-path-rounded-square"
                                            wire:loading.attr="disabled"
                                            wire:target="prepareReconnect,activateEnrollment"
                                        >
                                            Kết nối lại
                                        </x-filament::button>
                                    @endif
                                </div>
                                @if ($showReconnectConfirm)
                                    <p class="text-xs text-amber-800 dark:text-amber-200">
                                        Xác nhận thay identity/credential thương mại. Dữ liệu SEO-OPS Client không bị reset.
                                    </p>
                                @endif
                            </div>
                        @elseif (! $reconcileRunning && ! $isConnError && ($commercialGateMessage || ! $hasCommercialServiceEnabled))
                            <div class="rounded-lg border border-amber-500/40 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-950/30 dark:text-amber-200">
                                {{ $commercialGateMessage ?: 'Chưa có dịch vụ được kích hoạt. Vui lòng liên hệ quản trị viên hoặc đồng bộ lại quyền sử dụng.' }}
                            </div>
                        @endif

                        @if (! $isRecovery && ! $isConnError)
                            <div class="pt-1 flex flex-wrap items-center gap-3">
                                <x-filament::button
                                    wire:click="reconcileLicense"
                                    color="gray"
                                    size="sm"
                                    icon="heroicon-m-arrow-path"
                                    wire:loading.attr="disabled"
                                    wire:target="reconcileLicense,maybeAutoReconcileOnStep1"
                                    :disabled="$reconcileRunning"
                                >
                                    Đồng bộ lại
                                </x-filament::button>
                            </div>
                        @endif
                    </div>
                @else
                    {{-- Chưa kích hoạt --}}
                    <div class="space-y-4">
                        <div class="rounded-lg border border-amber-500/30 bg-amber-50/50 p-4 dark:border-amber-500/20 dark:bg-amber-950/20 text-xs text-amber-800 dark:text-amber-300">
                            <strong>Lưu ý:</strong> Cấu hình địa chỉ máy chủ OPS-SERVER và nhập mã kích hoạt (Enrollment Token bắt đầu bằng <code class="font-mono font-bold">tok_...</code>) do quản trị viên cấp.
                        </div>

                        @if ($enrollmentError)
                            <div class="rounded-lg border border-rose-500/30 bg-rose-50 p-3 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-950/30 dark:text-rose-400">
                                {{ $enrollmentError }}
                            </div>
                        @endif

                        {{-- 1. Trường cấu hình OPS-SERVER URL TRƯỚC Enrollment Token --}}
                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Địa chỉ máy chủ OPS-SERVER (Server URL)
                                <span class="text-rose-500">*</span>
                            </label>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Nhập URL máy chủ bản quyền (ví dụ: <code class="font-mono text-gray-700 dark:text-gray-300">http://localhost:3000</code> cho phát triển nội bộ, hoặc <code class="font-mono text-gray-700 dark:text-gray-300">https://ops.example.com</code>). Chỉ cho phép HTTP với máy cục bộ (loopback); các máy chủ mạng ngoài bắt buộc dùng HTTPS.
                            </p>
                            <div class="flex flex-col sm:flex-row gap-2">
                                <input
                                    type="text"
                                    wire:model="serverUrl"
                                    placeholder="http://localhost:3000 hoặc https://ops.example.com"
                                    class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 font-mono text-sm dark:border-white/10 dark:bg-gray-800 dark:text-white"
                                />
                                <x-filament::button
                                    wire:click="testServerConnection"
                                    color="gray"
                                    icon="heroicon-m-signal"
                                    class="shrink-0"
                                >
                                    Kiểm tra kết nối
                                </x-filament::button>
                            </div>

                            @if ($serverConnectionStatus === 'success')
                                <div class="rounded-lg border border-emerald-500/30 bg-emerald-50 p-2.5 text-xs text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-950/30 dark:text-emerald-300 flex items-center gap-2">
                                    <x-filament::icon icon="heroicon-m-check-circle" class="h-4 w-4 text-emerald-500 shrink-0" />
                                    <span>{{ $serverConnectionMessage }}</span>
                                </div>
                            @elseif ($serverConnectionStatus === 'error')
                                <div class="rounded-lg border border-rose-500/30 bg-rose-50 p-2.5 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-950/30 dark:text-rose-300 flex items-center gap-2">
                                    <x-filament::icon icon="heroicon-m-exclamation-circle" class="h-4 w-4 text-rose-500 shrink-0" />
                                    <span>{{ $serverConnectionMessage }}</span>
                                </div>
                            @endif
                        </div>

                        {{-- 2. Trường mã kích hoạt SAU Server URL --}}
                        <div class="space-y-2 pt-3 border-t border-gray-100 dark:border-white/5">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Mã kích hoạt (Enrollment Code)
                                <span class="text-rose-500">*</span>
                            </label>
                            <div class="flex gap-2">
                                <input
                                    type="text"
                                    wire:model="enrollmentToken"
                                    placeholder="tok_..."
                                    class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 font-mono text-sm dark:border-white/10 dark:bg-gray-800 dark:text-white"
                                />
                                <x-filament::button
                                    wire:click="activateEnrollment"
                                    icon="heroicon-m-bolt"
                                >
                                    Kích hoạt
                                </x-filament::button>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Chi tiết kỹ thuật thu gọn --}}
                <details class="text-xs text-gray-500 pt-2 border-t border-gray-100 dark:border-white/5">
                    <summary class="cursor-pointer font-medium hover:text-gray-700 dark:hover:text-gray-300">
                        Chi tiết kỹ thuật kết nối OPS-SERVER (Dành cho Quản trị viên)
                    </summary>
                    <div class="mt-2 space-y-1 pl-3 border-l-2 border-gray-200 dark:border-gray-700">
                        <p>Server URL: {{ $serverUrl ?: config('h1-license.base_url', 'Chưa cấu hình') }}</p>
                        <p>Giao thức bảo mật: H1 Signed Reconcile (HMAC SHA-256)</p>
                    </div>
                </details>

                {{-- Navigation Actions --}}
                <div class="flex items-center justify-between pt-4 border-t border-gray-100 dark:border-white/5">
                    <div></div>
                    <div class="flex items-center gap-2">
                        <x-filament::button
                            wire:click="nextStep"
                            icon="heroicon-m-arrow-right"
                            icon-position="after"
                            wire:loading.attr="disabled"
                            wire:target="nextStep,reconcileLicense,maybeAutoReconcileOnStep1"
                            :disabled="! $hasCommercialServiceEnabled || $reconcileRunning"
                        >
                            <span wire:loading.remove wire:target="reconcileLicense,maybeAutoReconcileOnStep1">
                                Tiếp tục: Cấu hình mặc định
                            </span>
                            <span wire:loading wire:target="reconcileLicense,maybeAutoReconcileOnStep1" class="inline-flex items-center gap-1">
                                <x-filament::loading-indicator class="h-4 w-4" />
                                Đang đồng bộ…
                            </span>
                        </x-filament::button>
                    </div>
                </div>
            </div>
        @endif

        {{-- STEP 2: CẤU HÌNH MẶC ĐỊNH (GOLDEN CONFIG) --}}
        @if ($currentStep === 2)
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900 space-y-6">
                <div>
                    <h3 class="text-lg font-bold text-gray-950 dark:text-white flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-cog-6-tooth" class="h-5 w-5 text-primary-500" />
                        Bước 2: Cấu hình mặc định (Golden Configuration)
                    </h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                        Hệ thống tự tải Golden Package. Apply cấu hình SEO chỉ khi dịch vụ SEO được kích hoạt.
                    </p>
                </div>

                @if ($goldenError)
                    <div class="rounded-lg border border-rose-500/30 bg-rose-50 p-3 text-sm text-rose-700 dark:border-rose-500/20 dark:bg-rose-950/30 dark:text-rose-400 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <span>{{ $goldenError }}</span>
                        <x-filament::button
                            wire:click="downloadGoldenPackage"
                            color="danger"
                            size="sm"
                            icon="heroicon-m-arrow-path"
                            wire:loading.attr="disabled"
                            wire:target="downloadGoldenPackage"
                            :disabled="! $isEnrolled || $goldenDownloading || $goldenApplying"
                        >
                            Retry
                        </x-filament::button>
                    </div>
                @endif

                {{-- Download status (separate from Apply) --}}
                <div class="rounded-lg border border-gray-200 bg-gray-50/60 p-4 dark:border-white/10 dark:bg-gray-800/40 space-y-3">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div class="space-y-1">
                            <div class="text-sm font-semibold text-gray-900 dark:text-white">Download</div>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                {{ $goldenDownloadMessage ?: 'Chưa tải package.' }}
                            </p>
                        </div>
                        @php
                            $downloadBadge = match ($goldenPackageStatus) {
                                'pending_apply' => ['Downloaded', 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300'],
                                'applied' => ['Downloaded', 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300'],
                                'error' => ['Failed', 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300'],
                                default => ['Pending', 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'],
                            };
                        @endphp
                        <span class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-semibold {{ $downloadBadge[1] }}">
                            {{ $downloadBadge[0] }}
                            @if ($goldenPackageVersion)
                                <span class="ml-1 opacity-80">v{{ $goldenPackageVersion }}</span>
                            @endif
                        </span>
                    </div>
                    @if ($goldenPackageStatus === 'error' || $goldenPackageStatus === 'none')
                        <x-filament::button
                            wire:click="downloadGoldenPackage"
                            color="gray"
                            size="sm"
                            icon="heroicon-m-arrow-down-tray"
                            wire:loading.attr="disabled"
                            wire:target="downloadGoldenPackage"
                            :disabled="! $isEnrolled || $goldenDownloading || $goldenApplying"
                        >
                            <span wire:loading.remove wire:target="downloadGoldenPackage">Retry Download</span>
                            <span wire:loading wire:target="downloadGoldenPackage">Đang tải…</span>
                        </x-filament::button>
                    @endif
                </div>

                {{-- Apply status --}}
                <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10 space-y-3 bg-white dark:bg-gray-900">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div class="text-sm font-semibold text-gray-900 dark:text-white">Apply</div>
                        @php
                            $applyBadge = match (true) {
                                $goldenPackageStatus === 'applied' || $goldenSuccess || $goldenStatus === 'in_sync' => ['Applied', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300'],
                                ! $goldenSeoEntitled && in_array($goldenPackageStatus, ['pending_apply', 'applied'], true) => ['Pending Apply', 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200'],
                                $goldenStatus === 'customized' && ! $overwriteGoldenConfiguration => ['Pending Apply', 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200'],
                                $goldenError && $goldenSeoEntitled => ['Failed', 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300'],
                                $goldenPackageStatus === 'pending_apply' && $goldenSeoEntitled => ['Pending Apply', 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200'],
                                default => ['Pending Apply', 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'],
                            };
                        @endphp
                        <span class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-semibold {{ $applyBadge[1] }}">
                            {{ $applyBadge[0] }}
                        </span>
                    </div>

                    @if (! $goldenSeoEntitled)
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            {{ $goldenReason ?: 'SEO chưa kích hoạt — đã tải Golden (nếu thành công), không Apply cấu hình SEO.' }}
                        </p>
                    @elseif ($goldenStatus === 'in_sync' || $goldenSuccess)
                        <p class="text-sm font-medium text-emerald-800 dark:text-emerald-300">Cấu hình DB đã khớp / đã Apply Golden.</p>
                    @elseif ($goldenStatus === 'customized')
                        <p class="text-sm text-amber-800 dark:text-amber-300">
                            Đã có cấu hình tùy chỉnh. Chỉ ghi đè khi bạn đồng ý.
                        </p>
                        <label class="flex items-start gap-2 rounded-md border border-gray-200 bg-gray-50/80 p-3 text-sm text-gray-700 dark:border-white/10 dark:bg-gray-800/40 dark:text-gray-300">
                            <input
                                type="checkbox"
                                wire:model.live="overwriteGoldenConfiguration"
                                @disabled($goldenApplying || $goldenDownloading)
                                class="mt-0.5 rounded border-gray-300 text-primary-600 focus:ring-primary-500 disabled:opacity-50"
                            />
                            <span>
                                <span class="font-semibold text-gray-900 dark:text-white">Ghi đè cấu hình hiện có bằng Golden Configuration</span>
                                @if ($overwriteGoldenConfiguration)
                                    <span class="block mt-1 font-medium text-amber-700 dark:text-amber-300">Cấu hình tùy chỉnh trong phạm vi Golden sẽ bị thay bằng bản chuẩn.</span>
                                @endif
                            </span>
                        </label>
                    @elseif ($goldenStatus === 'blank')
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            Cấu hình trống — hệ thống tự Apply Golden (không hỏi ghi đè).
                        </p>
                    @endif
                </div>

                {{-- Thống kê số lượng hiện tại --}}
                <div class="grid grid-cols-3 gap-3">
                    <div class="rounded-lg border border-gray-100 bg-gray-50/50 p-3 text-center dark:border-white/5 dark:bg-gray-800/50">
                        <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $goldenCounts['prompts'] }}</div>
                        <div class="text-xs text-gray-500 mt-0.5">Mẫu Prompts</div>
                    </div>
                    <div class="rounded-lg border border-gray-100 bg-gray-50/50 p-3 text-center dark:border-white/5 dark:bg-gray-800/50">
                        <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $goldenCounts['workflows'] }}</div>
                        <div class="text-xs text-gray-500 mt-0.5">Quy trình (Workflows)</div>
                    </div>
                    <div class="rounded-lg border border-gray-100 bg-gray-50/50 p-3 text-center dark:border-white/5 dark:bg-gray-800/50">
                        <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $goldenCounts['bindings'] }}</div>
                        <div class="text-xs text-gray-500 mt-0.5">Liên kết sự kiện</div>
                    </div>
                </div>

                {{-- Navigation Actions --}}
                <div class="flex items-center justify-between pt-4 border-t border-gray-100 dark:border-white/5">
                    <x-filament::button
                        wire:click="prevStep"
                        color="gray"
                        icon="heroicon-m-arrow-left"
                        wire:loading.attr="disabled"
                        wire:target="nextStep,downloadGoldenPackage"
                        :disabled="$goldenApplying || $goldenDownloading"
                    >
                        Quay lại
                    </x-filament::button>
                    <x-filament::button
                        wire:click="nextStep"
                        icon="heroicon-m-arrow-right"
                        icon-position="after"
                        wire:loading.attr="disabled"
                        wire:target="nextStep,downloadGoldenPackage"
                        :disabled="$goldenApplying || $goldenDownloading"
                    >
                        <span wire:loading.remove wire:target="nextStep">Tiếp tục: Kết nối AI</span>
                        <span wire:loading wire:target="nextStep" class="inline-flex items-center gap-1">
                            <x-filament::loading-indicator class="h-4 w-4" />
                            Đang xử lý cấu hình…
                        </span>
                    </x-filament::button>
                </div>
            </div>
        @endif

        {{-- STEP 3: KẾT NỐI AI --}}
        @if ($currentStep === 3)
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900 space-y-6">
                <div>
                    <h3 class="text-lg font-bold text-gray-950 dark:text-white flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-cpu-chip" class="h-5 w-5 text-primary-500" />
                        Bước 3: Kết nối nhà cung cấp AI
                    </h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                        Cấu hình Khóa API (API Key) để tạo bài viết, tối ưu từ khóa và phân tích nội dung tự động.
                    </p>
                </div>

                {{-- Danh sách kết nối hiện có --}}
                @if (count($existingAiConnections) > 0)
                    <div class="space-y-3">
                        <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">
                            Kết nối AI đang hoạt động ({{ count($existingAiConnections) }})
                        </h4>
                        <div class="space-y-2">
                            @foreach ($existingAiConnections as $conn)
                                <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50/50 p-3 dark:border-white/10 dark:bg-gray-800/40">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-100 text-primary-700 dark:bg-primary-950/60 dark:text-primary-400">
                                            <x-filament::icon icon="heroicon-m-sparkles" class="h-4 w-4" />
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                {{ $conn['name'] }}
                                                <span class="text-xs font-normal text-gray-500">({{ $conn['provider_label'] }})</span>
                                            </div>
                                            <div class="text-xs font-mono text-gray-400">{{ $conn['masked_key'] }}</div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                                            Hoạt động
                                        </span>
                                        <button
                                            type="button"
                                            wire:click="deleteAiConnection({{ $conn['id'] }})"
                                            wire:confirm="Bạn có chắc chắn muốn xóa kết nối AI này?"
                                            class="text-gray-400 hover:text-rose-600 dark:hover:text-rose-400 text-xs p-1"
                                            title="Xóa kết nối"
                                        >
                                            <x-filament::icon icon="heroicon-m-trash" class="h-4 w-4" />
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Form thêm kết nối mới --}}
                <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-800/20 space-y-4">
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                        {{ count($existingAiConnections) > 0 ? 'Thêm một kết nối AI khác' : 'Thêm kết nối AI đầu tiên' }}
                    </h4>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                                Nhà cung cấp AI (Provider)
                            </label>
                            <select
                                wire:model.live="aiProvider"
                                class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-white"
                            >
                                <option value="gemini">Google Gemini (Khuyên dùng)</option>
                                <option value="openai">OpenAI (GPT-4o, o3-mini)</option>
                                <option value="claude">Anthropic Claude (Claude 3.5)</option>
                                <option value="openrouter">OpenRouter (Đa mô hình)</option>
                                <option value="deepseek">DeepSeek AI</option>
                            </select>
                        </div>

                        <div class="space-y-1">
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                                Tên kết nối gợi nhớ
                            </label>
                            <input
                                type="text"
                                wire:model="aiConnectionName"
                                placeholder="Ví dụ: Google Gemini Pro"
                                class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-white"
                            />
                        </div>
                    </div>

                    <div class="space-y-1">
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                            Khóa API (API Key)
                        </label>
                        <input
                            type="password"
                            wire:model="aiApiKey"
                            placeholder="Dán mã khóa API tại đây (AIzaSy... hoặc sk-...)"
                            class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 font-mono text-sm dark:border-white/10 dark:bg-gray-800 dark:text-white"
                        />
                        <p class="text-xs text-gray-500">Khóa API sẽ được mã hóa an toàn trong cơ sở dữ liệu và không bao giờ hiển thị lại dưới dạng thô.</p>
                    </div>

                    {{-- Kết quả kiểm tra --}}
                    @if ($aiTestStatus === 'success')
                        <div class="rounded-lg border border-emerald-500/30 bg-emerald-50/50 p-3 text-xs text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-950/20 dark:text-emerald-300 flex items-center gap-2">
                            <x-filament::icon icon="heroicon-m-check-circle" class="h-4 w-4 flex-shrink-0" />
                            <span>{{ $aiTestDetails }}</span>
                        </div>
                    @elseif ($aiTestStatus === 'error')
                        <div class="rounded-lg border border-rose-500/30 bg-rose-50 p-3 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-950/30 dark:text-rose-400 flex items-center gap-2">
                            <x-filament::icon icon="heroicon-m-x-circle" class="h-4 w-4 flex-shrink-0" />
                            <span>{{ $aiTestDetails }}</span>
                        </div>
                    @endif

                    <div class="flex items-center gap-3 pt-2">
                        <x-filament::button
                            wire:click="testAiConnection"
                            color="gray"
                            size="sm"
                            icon="heroicon-m-sparkles"
                        >
                            Kiểm tra kết nối
                        </x-filament::button>
                        <x-filament::button
                            wire:click="saveAiConnection"
                            size="sm"
                            icon="heroicon-m-check"
                        >
                            Lưu kết nối AI
                        </x-filament::button>
                    </div>
                </div>

                {{-- Navigation Actions --}}
                <div class="flex items-center justify-between pt-4 border-t border-gray-100 dark:border-white/5">
                    <x-filament::button
                        wire:click="prevStep"
                        color="gray"
                        icon="heroicon-m-arrow-left"
                    >
                        Quay lại
                    </x-filament::button>
                    <x-filament::button
                        wire:click="nextStep"
                        icon="heroicon-m-arrow-right"
                        icon-position="after"
                    >
                        Tiếp tục: Tác vụ nền định kỳ
                    </x-filament::button>
                </div>
            </div>
        @endif

        {{-- STEP 4: AAPANEL CRON --}}
        @if ($currentStep === 4)
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900 space-y-6">
                <div>
                    <h3 class="text-lg font-bold text-gray-950 dark:text-white flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-clock" class="h-5 w-5 text-primary-500" />
                        Bước 4: {{ __('aapanel_cron.step_title') }}
                    </h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                        {{ __('aapanel_cron.step_description') }}
                    </p>
                </div>

                <div class="rounded-lg border border-gray-200 bg-gray-50/70 p-4 dark:border-white/10 dark:bg-gray-800/40 space-y-3">
                    <p class="text-xs text-gray-600 dark:text-gray-300">
                        {{ __('aapanel_cron.download_hint') }}
                    </p>
                    <p class="text-xs text-amber-700 dark:text-amber-300">
                        {{ __('aapanel_cron.duplicate_warning') }}
                    </p>
                    <x-filament::button
                        color="primary"
                        icon="heroicon-m-arrow-down-tray"
                        wire:click="downloadAaPanelCronJson"
                        wire:loading.attr="disabled"
                        wire:target="downloadAaPanelCronJson"
                    >
                        <span wire:loading.remove wire:target="downloadAaPanelCronJson">{{ __('aapanel_cron.download_json') }}</span>
                        <span wire:loading wire:target="downloadAaPanelCronJson" class="inline-flex items-center gap-2">
                            <x-filament::loading-indicator class="h-4 w-4" />
                            …
                        </span>
                    </x-filament::button>
                </div>

                <div class="space-y-2">
                    <h4 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('aapanel_cron.guide_title') }}</h4>
                    <ol class="list-decimal space-y-1.5 pl-5 text-sm text-gray-700 dark:text-gray-300">
                        <li>{{ __('aapanel_cron.guide_1') }}</li>
                        <li>{{ __('aapanel_cron.guide_2') }}</li>
                        <li>{{ __('aapanel_cron.guide_3') }}</li>
                        <li>{{ __('aapanel_cron.guide_4') }}</li>
                    </ol>
                </div>

                <div class="flex items-center justify-between pt-4 border-t border-gray-100 dark:border-white/5">
                    <x-filament::button
                        wire:click="prevStep"
                        color="gray"
                        icon="heroicon-m-arrow-left"
                    >
                        {{ __('aapanel_cron.back') }}
                    </x-filament::button>
                    <x-filament::button
                        wire:click="nextStep"
                        icon="heroicon-m-arrow-right"
                        icon-position="after"
                    >
                        {{ __('aapanel_cron.continue') }}
                    </x-filament::button>
                </div>
            </div>
        @endif

        {{-- STEP 5: HOÀN TẤT & TỔNG KẾT --}}
        @if ($currentStep === 5)
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900 space-y-6">
                <div>
                    <h3 class="text-lg font-bold text-gray-950 dark:text-white flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-check-badge" class="h-5 w-5 text-emerald-500" />
                        Bước 5: Tổng kết tình trạng hệ thống
                    </h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                        Kiểm tra danh sách hạng mục đã thiết lập trước khi bắt đầu sử dụng bảng điều khiển SEO-OPS.
                    </p>
                </div>

                {{-- Checklist Items --}}
                <div class="space-y-3">
                    @foreach ($checklist as $item)
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between rounded-lg border border-gray-200 bg-gray-50/50 p-4 dark:border-white/10 dark:bg-gray-800/40 gap-3">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    @if ($item['badge'] === 'emerald')
                                        <x-filament::icon icon="heroicon-m-check-circle" class="h-5 w-5 text-emerald-500" />
                                    @elseif ($item['badge'] === 'blue')
                                        <x-filament::icon icon="heroicon-m-information-circle" class="h-5 w-5 text-blue-500" />
                                    @elseif ($item['badge'] === 'amber')
                                        <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-5 w-5 text-amber-500" />
                                    @else
                                        <x-filament::icon icon="heroicon-m-minus-circle" class="h-5 w-5 text-gray-400" />
                                    @endif
                                    <span class="text-sm font-bold text-gray-900 dark:text-white">{{ $item['title'] }}</span>
                                </div>
                                <p class="text-xs text-gray-600 dark:text-gray-300 pl-7">
                                    {{ $item['description'] }}
                                </p>
                            </div>

                            <div class="flex items-center gap-3 pl-7 sm:pl-0 flex-shrink-0">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold
                                    @if ($item['badge'] === 'emerald')
                                        bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300
                                    @elseif ($item['badge'] === 'blue')
                                        bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300
                                    @elseif ($item['badge'] === 'amber')
                                        bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300
                                    @else
                                        bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300
                                    @endif
                                ">
                                    {{ $item['status_label'] }}
                                </span>

                                @if ($item['link'] && $item['link_label'])
                                    <a
                                        href="{{ $item['link'] }}"
                                        target="_blank"
                                        class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400 inline-flex items-center gap-1"
                                    >
                                        {{ $item['link_label'] }}
                                        <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-3 w-3" />
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Navigation Actions --}}
                <div class="flex items-center justify-between pt-4 border-t border-gray-100 dark:border-white/5">
                    <x-filament::button
                        wire:click="prevStep"
                        color="gray"
                        icon="heroicon-m-arrow-left"
                    >
                        Quay lại Bước 4
                    </x-filament::button>
                    <x-filament::button
                        wire:click="finishQuickSetup"
                        color="primary"
                        icon="heroicon-m-check"
                    >
                        Hoàn tất & Vào Bảng điều khiển
                    </x-filament::button>
                </div>
            </div>
        @endif

    </div>
</x-filament-panels::page>

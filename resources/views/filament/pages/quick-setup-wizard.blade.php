<x-filament-panels::page>
    <div class="max-w-4xl mx-auto space-y-6">

        {{-- STEPPER HEADER --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 sm:p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
            @php
                $steps = [
                    1 => ['short' => 'Kích hoạt', 'full' => 'Kích hoạt dịch vụ', 'desc' => 'Bản quyền OPS'],
                    2 => ['short' => 'Cấu hình', 'full' => 'Cấu hình mặc định', 'desc' => 'Golden Config'],
                    3 => ['short' => 'Kết nối AI', 'full' => 'Kết nối AI', 'desc' => 'API Providers'],
                    4 => ['short' => 'Website', 'full' => 'Website đầu tiên', 'desc' => 'Tên miền SEO'],
                    5 => ['short' => 'Hoàn tất', 'full' => 'Hoàn tất & Tổng kết', 'desc' => 'Sẵn sàng'],
                ];
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
                        Bước {{ $currentStep }}/5
                    </span>
                </div>

                {{-- 5-segment interactive tap bar --}}
                <div class="flex items-center gap-1.5 w-full">
                    @for ($i = 1; $i <= 5; $i++)
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
                    {{-- Đã kích hoạt --}}
                    <div class="rounded-lg border border-emerald-500/30 bg-emerald-50/50 p-4 dark:border-emerald-500/20 dark:bg-emerald-950/20 space-y-3">
                        <div class="flex items-center gap-2 text-emerald-700 dark:text-emerald-400 font-semibold text-sm">
                            <x-filament::icon icon="heroicon-m-check-circle" class="h-5 w-5" />
                            <span>Thiết bị đã được kích hoạt thành công với OPS-SERVER</span>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs text-gray-600 dark:text-gray-300">
                            <div>
                                <span class="font-medium text-gray-500">Mã thiết bị (Installation ID):</span>
                                <div class="font-mono text-gray-900 dark:text-white truncate">{{ $installationDetails['installation_id'] }}</div>
                            </div>
                            <div>
                                <span class="font-medium text-gray-500">Phiên bản chứng chỉ:</span>
                                <div class="font-medium text-gray-900 dark:text-white">{{ $installationDetails['credential_version'] }}</div>
                            </div>
                            <div class="sm:col-span-2">
                                <span class="font-medium text-gray-500">Máy chủ OPS-SERVER liên kết:</span>
                                <div class="flex items-center gap-2 mt-1">
                                    <input
                                        type="text"
                                        readonly
                                        disabled
                                        value="{{ $installationDetails['control_server_url'] }}"
                                        class="block w-full rounded-lg border-gray-200 bg-gray-50 text-gray-600 font-mono text-xs dark:border-white/10 dark:bg-gray-800 dark:text-gray-400 cursor-not-allowed"
                                    />
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300 whitespace-nowrap">
                                        <x-filament::icon icon="heroicon-m-lock-closed" class="h-3.5 w-3.5 text-gray-400" />
                                        Đã khóa
                                    </span>
                                </div>
                                <p class="text-[11px] text-gray-400 mt-1">
                                    Máy chủ đã được liên kết cố định với Installation này. Việc đổi máy chủ yêu cầu quy trình phục hồi ủy quyền.
                                </p>
                            </div>
                            <div>
                                <span class="font-medium text-gray-500">Dịch vụ SEO:</span>
                                <div>
                                    @if ($installationDetails['seo_active'])
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                                            Hoạt động ({{ $installationDetails['seo_quota'] }})
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                            Chưa cấp quyền
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <div>
                                <span class="font-medium text-gray-500">Dịch vụ Seeding:</span>
                                <div>
                                    @if ($installationDetails['seeding_active'])
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                                            Hoạt động
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                            Chưa cấp quyền
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="pt-2 flex flex-wrap items-center gap-3">
                            <x-filament::button
                                wire:click="reconcileLicense"
                                color="gray"
                                size="sm"
                                icon="heroicon-m-arrow-path"
                            >
                                Kiểm tra / Đồng bộ lại bản quyền
                            </x-filament::button>
                            <span class="text-xs text-gray-400">
                                Đồng bộ lần cuối: {{ $installationDetails['last_reconciled'] }}
                            </span>
                        </div>
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
                        >
                            Tiếp tục: Cấu hình mặc định
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
                        Cung cấp hệ thống Mẫu gợi ý (Prompts), Quy trình làm việc (Workflows) và Cài đặt SEO chuẩn mực được tuyển chọn.
                    </p>
                </div>

                @if ($goldenError)
                    <div class="rounded-lg border border-rose-500/30 bg-rose-50 p-3 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-950/30 dark:text-rose-400">
                        {{ $goldenError }}
                    </div>
                @endif

                @if (! $isSeoLicensed)
                    <div class="rounded-lg border border-amber-500/30 bg-amber-50/50 p-4 dark:border-amber-500/20 dark:bg-amber-950/20 space-y-2">
                        <div class="flex items-center gap-2 font-medium text-amber-800 dark:text-amber-300 text-sm">
                            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-5 w-5" />
                            <span>Dịch vụ SEO chưa được cấp phép</span>
                        </div>
                        <p class="text-xs text-amber-700 dark:text-amber-400">
                            Tính năng Cấu hình chuẩn Golden chỉ áp dụng khi hệ thống có bản quyền dịch vụ SEO. Bạn có thể bỏ qua bước này để tiếp tục thiết lập các phần khác.
                        </p>
                    </div>
                @elseif ($goldenStatus === 'blank')
                    <div class="rounded-lg border border-primary-500/30 bg-primary-50/30 p-5 dark:border-primary-500/20 dark:bg-primary-950/20 space-y-4">
                        <div class="flex items-start gap-3">
                            <x-filament::icon icon="heroicon-o-sparkles" class="h-6 w-6 text-primary-500 flex-shrink-0 mt-0.5" />
                            <div class="space-y-1">
                                <h4 class="text-sm font-bold text-gray-900 dark:text-white">
                                    Áp dụng Cấu hình chuẩn SEO-OPS
                                </h4>
                                <p class="text-xs text-gray-600 dark:text-gray-300">
                                    Hệ thống hiện tại chưa có dữ liệu cấu hình. Nạp cấu hình chuẩn sẽ trang bị đầy đủ các Prompt viết bài chuẩn SEO, quy trình tự động hóa bài viết và các thiết lập ghi điểm chất lượng.
                                </p>
                            </div>
                        </div>

                        <div class="pt-2">
                            <x-filament::button
                                wire:click="applyGoldenConfiguration"
                                icon="heroicon-m-arrow-down-tray"
                            >
                                Nạp Cấu hình chuẩn ngay
                            </x-filament::button>
                        </div>
                    </div>
                @elseif ($goldenStatus === 'in_sync')
                    <div class="rounded-lg border border-emerald-500/30 bg-emerald-50/50 p-4 dark:border-emerald-500/20 dark:bg-emerald-950/20 space-y-2">
                        <div class="flex items-center gap-2 font-semibold text-emerald-800 dark:text-emerald-300 text-sm">
                            <x-filament::icon icon="heroicon-m-check-circle" class="h-5 w-5" />
                            <span>Cấu hình hiện tại đang khớp hoàn toàn với Cấu hình chuẩn</span>
                        </div>
                        <p class="text-xs text-emerald-700 dark:text-emerald-400">
                            Hệ thống đang hoạt động với phiên bản cấu hình chuẩn Golden mới nhất. Không cần nạp lại.
                        </p>
                    </div>
                @elseif ($goldenStatus === 'customized')
                    <div class="rounded-lg border border-blue-500/30 bg-blue-50/50 p-4 dark:border-blue-500/20 dark:bg-blue-950/20 space-y-2">
                        <div class="flex items-center gap-2 font-semibold text-blue-800 dark:text-blue-300 text-sm">
                            <x-filament::icon icon="heroicon-m-shield-check" class="h-5 w-5" />
                            <span>Đã phát hiện Cấu hình tùy chỉnh riêng của bạn</span>
                        </div>
                        <p class="text-xs text-blue-700 dark:text-blue-400">
                            Bạn đã có Prompts hoặc Quy trình được điều chỉnh riêng. SEO-OPS tôn trọng và bảo toàn 100% dữ liệu tùy chỉnh của bạn, không tự động ghi đè hoặc nạp lại.
                        </p>
                    </div>
                @endif

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
                    >
                        Quay lại
                    </x-filament::button>
                    <x-filament::button
                        wire:click="nextStep"
                        icon="heroicon-m-arrow-right"
                        icon-position="after"
                    >
                        Tiếp tục: Kết nối AI
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
                        Tiếp tục: Thêm Website
                    </x-filament::button>
                </div>
            </div>
        @endif

        {{-- STEP 4: WEBSITE ĐẦU TIÊN --}}
        @if ($currentStep === 4)
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900 space-y-6">
                <div>
                    <h3 class="text-lg font-bold text-gray-950 dark:text-white flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-globe-alt" class="h-5 w-5 text-primary-500" />
                        Bước 4: Thiết lập Website đầu tiên
                    </h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                        Khai báo website vệ tinh hoặc website chính để SEO-OPS theo dõi từ khóa và phân phối bài viết.
                    </p>
                </div>

                @if (! $isSeoLicensed)
                    <div class="rounded-lg border border-amber-500/30 bg-amber-50/50 p-4 dark:border-amber-500/20 dark:bg-amber-950/20 space-y-2">
                        <div class="flex items-center gap-2 font-medium text-amber-800 dark:text-amber-300 text-sm">
                            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-5 w-5" />
                            <span>Yêu cầu bản quyền Dịch vụ SEO</span>
                        </div>
                        <p class="text-xs text-amber-700 dark:text-amber-400">
                            Quản lý website yêu cầu bản quyền dịch vụ SEO hợp lệ. Khi có bản quyền, bạn có thể quay lại trang này bất kỳ lúc nào để thêm website.
                        </p>
                    </div>
                @else
                    {{-- Danh sách website hiện có --}}
                    @if (count($existingSites) > 0)
                        <div class="space-y-3">
                            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                Website hiện có trong hệ thống ({{ count($existingSites) }})
                            </h4>
                            <div class="space-y-2">
                                @foreach ($existingSites as $site)
                                    <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50/50 p-3 dark:border-white/10 dark:bg-gray-800/40">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                                <x-filament::icon icon="heroicon-m-globe-alt" class="h-4 w-4" />
                                            </div>
                                            <div>
                                                <div class="text-sm font-bold text-gray-900 dark:text-white">
                                                    {{ $site['domain'] }}
                                                </div>
                                                <div class="text-xs text-gray-400">
                                                    SSL: {{ $site['ssl'] ? 'Bật (HTTPS)' : 'Tắt (HTTP)' }} • Ngày tạo: {{ $site['created_at'] }}
                                                </div>
                                            </div>
                                        </div>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                            @if ($site['status'] === 'active')
                                                bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300
                                            @else
                                                bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300
                                            @endif
                                        ">
                                            {{ $site['status'] === 'active' ? 'Hoạt động' : 'Chưa kích hoạt' }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Form thêm website mới --}}
                    <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-800/20 space-y-4">
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                            {{ count($existingSites) > 0 ? 'Thêm một website khác' : 'Thêm website đầu tiên của bạn' }}
                        </h4>

                        @if ($siteError)
                            <div class="rounded-lg border border-rose-500/30 bg-rose-50 p-3 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-950/30 dark:text-rose-400">
                                {{ $siteError }}
                            </div>
                        @endif

                        <div class="space-y-3">
                            <div class="space-y-1">
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                                    Tên miền (Domain)
                                </label>
                                <input
                                    type="text"
                                    wire:model="newSiteDomain"
                                    placeholder="ví dụ: mywebsite.com"
                                    class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 font-mono text-sm dark:border-white/10 dark:bg-gray-800 dark:text-white"
                                />
                                <p class="text-xs text-gray-500">Chỉ cần nhập tên miền chính, không cần nhập https://</p>
                            </div>

                            <div class="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    id="site_ssl_toggle"
                                    wire:model="newSiteSsl"
                                    class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-white/10 dark:bg-gray-800"
                                />
                                <label for="site_ssl_toggle" class="text-xs font-medium text-gray-700 dark:text-gray-300 cursor-pointer">
                                    Sử dụng kết nối bảo mật SSL / HTTPS
                                </label>
                            </div>
                        </div>

                        <div class="pt-2">
                            <x-filament::button
                                wire:click="createSite"
                                size="sm"
                                icon="heroicon-m-plus"
                            >
                                Thêm Website
                            </x-filament::button>
                        </div>
                    </div>
                @endif

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
                        Tiếp tục: Hoàn tất & Tổng kết
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

                {{-- Hướng dẫn aaPanel Cron --}}
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-gray-800/50 space-y-2">
                    <div class="flex items-center gap-2 font-semibold text-gray-900 dark:text-white text-xs">
                        <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4 text-primary-500" />
                        <span>Cấu hình Cronjob định kỳ trên máy chủ (aaPanel):</span>
                    </div>
                    <div class="rounded bg-gray-900 px-3 py-2 text-xs font-mono text-emerald-400 overflow-x-auto select-all">
                        * * * * * cd {{ base_path() }} &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1
                    </div>
                    <p class="text-xs text-gray-500">
                        Chạy mỗi phút một lần để xử lý hàng đợi bài viết, đồng bộ chỉ số SEO và giám sát tác vụ nền.
                    </p>
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

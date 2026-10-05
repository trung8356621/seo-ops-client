<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-lg font-medium text-gray-900 dark:text-white">Nhập dữ liệu SEO di động (Portable Data Import)</h2>
            <p class="mt-2 text-sm text-gray-500">
                Nhập gói transfer vào client mới. Hệ thống sẽ kiểm tra cấu trúc gói, xác thực tính toàn vẹn mã băm (SHA-256) và tái tạo toàn bộ trạng thái dữ liệu nghiệp vụ SEO.
            </p>

            <form wire:submit.prevent="inspectPackage" class="mt-6 space-y-4">
                {{ $this->form }}

                <div class="flex items-center gap-3">
                    <x-filament::button type="submit" color="primary" icon="heroicon-o-magnifying-glass">
                        Kiểm tra gói (Preflight Inspection)
                    </x-filament::button>

                    <a href="{{ \App\Filament\Pages\ServiceStatusOverview::getUrl() }}" class="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                        Quay lại Dịch vụ
                    </a>
                </div>
            </form>
        </div>

        @if ($inspectionResult)
            <div class="rounded-xl border border-blue-200 bg-blue-50/50 p-6 shadow-sm dark:border-blue-900 dark:bg-blue-950/20">
                <h3 class="text-base font-semibold text-blue-900 dark:text-blue-100">Kết quả kiểm tra gói dữ liệu (Preflight):</h3>
                <dl class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-gray-500">Định dạng gói:</dt>
                        <dd class="font-medium text-gray-900 dark:text-white">{{ $inspectionResult['format'] }} (v{{ $inspectionResult['format_version'] }})</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Thời điểm export nguồn:</dt>
                        <dd class="font-medium text-gray-900 dark:text-white">{{ $inspectionResult['exported_at'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Kết nối SEO mục tiêu:</dt>
                        <dd class="font-medium {{ $inspectionResult['service_ready'] ? 'text-emerald-600' : 'text-rose-600' }}">
                            {{ $inspectionResult['service_ready'] ? 'Sẵn sàng' : 'Chưa kết nối được' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Database mục tiêu trống:</dt>
                        <dd class="font-medium {{ $inspectionResult['target_empty'] ? 'text-emerald-600' : 'text-amber-600' }}">
                            {{ $inspectionResult['target_empty'] ? 'Có (Trống hoàn toàn)' : 'KHÔNG TRỐNG (Có dữ liệu)' }}
                        </dd>
                    </div>
                </dl>

                @if (! $inspectionResult['target_empty'] && ! $force)
                    <div class="mt-4 rounded-lg bg-amber-100 p-3 text-xs text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                        <strong>Cảnh báo:</strong> Database SEO đích không trống. Bạn cần bật "Bỏ qua kiểm tra database trống" trong form ở trên nếu muốn ghi đè.
                    </div>
                @endif

                <div class="mt-6 border-t border-blue-200/60 pt-4 dark:border-blue-800/60">
                    <h4 class="text-xs font-semibold uppercase tracking-wider text-blue-800 dark:text-blue-200">Danh sách các tập dữ liệu trong gói:</h4>
                    <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
                        @foreach ($inspectionResult['datasets'] as $dKey => $info)
                            <div class="rounded-lg border border-blue-100 bg-white p-2.5 text-xs shadow-xs dark:border-gray-800 dark:bg-gray-900">
                                <span class="block font-medium text-gray-600 dark:text-gray-400">{{ $dKey }}</span>
                                <span class="font-bold text-gray-900 dark:text-white">{{ number_format($info['count']) }} records</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if ($inspectionResult['service_ready'] && ($inspectionResult['target_empty'] || $force))
                    <div class="mt-6">
                        <x-filament::button wire:click="runImport" wire:loading.attr="disabled" color="success" icon="heroicon-o-play">
                            <span wire:loading.remove wire:target="runImport">Bắt đầu Import dữ liệu ngay</span>
                            <span wire:loading wire:target="runImport">Đang import dữ liệu...</span>
                        </x-filament::button>
                    </div>
                @endif
            </div>
        @endif

        @if ($importResult)
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Báo cáo kết quả Import (Run ID: {{ $importResult['run_id'] }})</h3>

                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <div class="rounded-lg bg-emerald-50 p-3 text-center dark:bg-emerald-950/30">
                        <span class="block text-xs text-emerald-600 dark:text-emerald-400">Đã Import</span>
                        <span class="text-lg font-bold text-emerald-700 dark:text-emerald-300">{{ number_format($importResult['total_imported']) }}</span>
                    </div>
                    <div class="rounded-lg bg-rose-50 p-3 text-center dark:bg-rose-950/30">
                        <span class="block text-xs text-rose-600 dark:text-rose-400">Thất bại</span>
                        <span class="text-lg font-bold text-rose-700 dark:text-rose-300">{{ number_format($importResult['total_failed']) }}</span>
                    </div>
                    <div class="rounded-lg bg-amber-50 p-3 text-center dark:bg-amber-950/30">
                        <span class="block text-xs text-amber-600 dark:text-amber-400">Bị chặn (Parent lỗi)</span>
                        <span class="text-lg font-bold text-amber-700 dark:text-amber-300">{{ number_format($importResult['total_blocked']) }}</span>
                    </div>
                    <div class="rounded-lg bg-sky-50 p-3 text-center dark:bg-sky-950/30">
                        <span class="block text-xs text-sky-600 dark:text-sky-400">Cảnh báo</span>
                        <span class="text-lg font-bold text-sky-700 dark:text-sky-300">{{ number_format($importResult['total_warnings']) }}</span>
                    </div>
                    <div class="rounded-lg bg-purple-50 p-3 text-center dark:bg-purple-950/30">
                        <span class="block text-xs text-purple-600 dark:text-purple-400">Thiếu Reference</span>
                        <span class="text-lg font-bold text-purple-700 dark:text-purple-300">{{ number_format($importResult['total_missing_refs']) }}</span>
                    </div>
                </div>

                @if (! empty($importResult['retry_package_path']))
                    <div class="mt-6 flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
                        <div>
                            <h4 class="text-sm font-semibold text-amber-900 dark:text-amber-200">Gói cách ly & thử lại (Quarantine Retry Package)</h4>
                            <p class="mt-0.5 text-xs text-amber-700 dark:text-amber-300">
                                Đã lưu các bản ghi bị lỗi từ gói gốc cùng context cần thiết để chẩn đoán hoặc import lại sau khi sửa.
                            </p>
                        </div>
                        <x-filament::button wire:click="downloadRetryPackage" color="warning" icon="heroicon-o-arrow-down-tray">
                            Tải file Retry ZIP
                        </x-filament::button>
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>

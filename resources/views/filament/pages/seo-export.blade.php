<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-lg font-medium text-gray-900 dark:text-white">Xuất dữ liệu SEO di động (Portable Data Export)</h2>
            <p class="mt-2 text-sm text-gray-500">
                Tác vụ chạy ngầm trên hàng đợi <code>client-transfer</code>. Bộ nhớ và thời gian được phân bổ theo từng lát cắt dữ liệu an toàn.
            </p>

            <div class="mt-6 flex items-center gap-3">
                <x-filament::button wire:click="runExport" wire:loading.attr="disabled" color="primary" icon="heroicon-o-arrow-up-tray">
                    <span wire:loading.remove wire:target="runExport">{{ $this->latestRun ? 'Regenerate' : 'Bắt đầu xuất dữ liệu' }}</span>
                    <span wire:loading wire:target="runExport">Đang đưa vào hàng đợi...</span>
                </x-filament::button>

                <a href="{{ \App\Filament\Pages\ServiceStatusOverview::getUrl() }}" class="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                    Quay lại Dịch vụ
                </a>
            </div>
        </div>

        @php($active = $this->activeRun)
        @php($latest = $this->latestRun)
        @php($failed = $this->failedRun)

        @if ($active)
            <div wire:poll.2s class="rounded-xl border border-blue-200 bg-blue-50/50 p-6 shadow-sm dark:border-blue-900 dark:bg-blue-950/20">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-blue-900 dark:text-blue-100">Đang xử lý xuất dữ liệu...</h3>
                        <p class="mt-1 text-xs text-blue-700 dark:text-blue-300">
                            Run ID: <span class="font-mono">{{ $active->run_id }}</span> | Giai đoạn: <span class="font-semibold">{{ $active->phase }}</span>
                            @if ($active->current_dataset)
                                | Tập dữ liệu: <span class="font-semibold">{{ $active->current_dataset }}</span>
                            @endif
                        </p>
                    </div>
                    <span class="inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                        {{ ucfirst($active->status) }}
                    </span>
                </div>
                <div class="mt-4 text-xs text-blue-700 dark:text-blue-300">
                    Đã xử lý: {{ number_format($active->processed_records) }} bản ghi
                </div>
            </div>
        @elseif ($failed)
            <div class="rounded-xl border border-rose-200 bg-rose-50/50 p-6 shadow-sm dark:border-rose-900 dark:bg-rose-950/20">
                <h3 class="text-base font-semibold text-rose-900 dark:text-rose-100">Xuất dữ liệu thất bại</h3>
                <p class="mt-1 text-xs text-rose-700 dark:text-rose-300">Lỗi: {{ $failed->error_message }}</p>
                @if ($latest)
                    <p class="mt-1 text-xs text-rose-700 dark:text-rose-300">Bản export thành công gần nhất vẫn có thể tải xuống.</p>
                @endif
            </div>
        @endif

        @if ($latest)
            <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-6 shadow-sm dark:border-emerald-900 dark:bg-emerald-950/20">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-emerald-900 dark:text-emerald-100">Latest Export</h3>
                        <p class="mt-1 text-xs text-emerald-700 dark:text-emerald-300">
                            Generated at: {{ $latest->finished_at?->format('Y-m-d H:i:s') }}
                            | Total records: {{ number_format($latest->processed_records) }}
                            | File size: {{ $this->latestFileSize }}
                        </p>
                    </div>
                    <x-filament::button wire:click="downloadPackage" color="success" icon="heroicon-o-arrow-down-tray">
                        Tải file ZIP
                    </x-filament::button>
                </div>
            </div>
        @endif

        {{-- Retry Failed Import Section --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-lg font-medium text-gray-900 dark:text-white">Thử lại import lỗi (Retry Failed Import Flow)</h2>
            <p class="mt-2 text-sm text-gray-500">
                Tải lên file Failure Request ZIP nhận được từ hệ thống đích để kiểm tra các bản ghi thất bại/bị chặn và xuất gói dữ liệu tươi (Retry Data ZIP) tương ứng.
            </p>

            <form wire:submit.prevent="inspectFailurePackage" class="mt-6 space-y-4">
                {{ $this->form }}

                <div class="flex items-center gap-3">
                    <x-filament::button type="submit" color="primary" icon="heroicon-o-magnifying-glass">
                        Kiểm tra gói lỗi (Inspect Failure Package)
                    </x-filament::button>
                </div>
            </form>

            @if ($this->failureInspection)
                <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50/50 p-4 dark:border-amber-900 dark:bg-amber-950/20">
                    <h3 class="text-sm font-semibold text-amber-900 dark:text-amber-100">Thông tin gói thất bại:</h3>
                    <dl class="mt-2 grid grid-cols-1 gap-2 text-xs sm:grid-cols-3 text-amber-800 dark:text-amber-200">
                        <div>
                            <dt class="font-medium">Original Run ID:</dt>
                            <dd class="font-mono">{{ $this->failureInspection['original_import_run_id'] }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">Bản ghi lỗi gốc (Failed):</dt>
                            <dd class="font-bold">{{ number_format($this->failureInspection['failed_roots']) }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">Bản ghi bị chặn (Blocked):</dt>
                            <dd class="font-bold">{{ number_format($this->failureInspection['blocked']) }}</dd>
                        </div>
                    </dl>

                    <div class="mt-4">
                        <x-filament::button wire:click="runRetryExport" wire:loading.attr="disabled" color="warning" icon="heroicon-o-arrow-path">
                            <span wire:loading.remove wire:target="runRetryExport">Xuất dữ liệu thử lại (Export Retry Data ZIP)</span>
                            <span wire:loading wire:target="runRetryExport">Đang đưa vào hàng đợi client-transfer...</span>
                        </x-filament::button>
                    </div>
                </div>
            @endif

            @php($retryRun = $this->latestRetryRun)
            @if ($retryRun)
                <div class="mt-6 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-white">Gói Retry Data gần nhất</h4>
                            <p class="mt-1 text-xs text-gray-500">
                                Trạng thái: <span class="font-semibold">{{ ucfirst($retryRun->status) }}</span>
                                | Bản ghi: {{ number_format($retryRun->processed_records) }}
                            </p>
                        </div>
                        @if ($retryRun->isCompleted() && $retryRun->artifact_path && is_file($retryRun->artifact_path))
                            <x-filament::button wire:click="downloadRetryDataPackage" color="success" icon="heroicon-o-arrow-down-tray">
                                Tải gói Retry Data ZIP
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            @endif
        </div>

    </div>
</x-filament-panels::page>

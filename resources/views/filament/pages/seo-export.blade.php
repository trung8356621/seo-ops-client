<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-lg font-medium text-gray-900 dark:text-white">Xuất dữ liệu SEO di động (Portable Data Export)</h2>
            <p class="mt-2 text-sm text-gray-500">
                Tác vụ chạy ngầm trên hàng đợi <code>client-transfer</code>. Bộ nhớ và thời gian được phân bổ theo từng lát cắt dữ liệu an toàn.
            </p>

            <div class="mt-6 flex items-center gap-3">
                <x-filament::button wire:click="runExport" wire:loading.attr="disabled" color="primary" icon="heroicon-o-arrow-up-tray">
                    <span wire:loading.remove wire:target="runExport">Bắt đầu xuất dữ liệu</span>
                    <span wire:loading wire:target="runExport">Đang đưa vào hàng đợi...</span>
                </x-filament::button>

                <a href="{{ \App\Filament\Pages\ServiceStatusOverview::getUrl() }}" class="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                    Quay lại Dịch vụ
                </a>
            </div>
        </div>

        @if ($this->run)
            @if ($this->run->isRunning())
                <div wire:poll.2s class="rounded-xl border border-blue-200 bg-blue-50/50 p-6 shadow-sm dark:border-blue-900 dark:bg-blue-950/20">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-blue-900 dark:text-blue-100">Đang xử lý xuất dữ liệu...</h3>
                            <p class="mt-1 text-xs text-blue-700 dark:text-blue-300">
                                Run ID: <span class="font-mono">{{ $this->run->run_id }}</span> | Giai đoạn: <span class="font-semibold">{{ $this->run->phase }}</span>
                                @if ($this->run->current_dataset)
                                    | Tập dữ liệu: <span class="font-semibold">{{ $this->run->current_dataset }}</span>
                                @endif
                            </p>
                        </div>
                        <div class="text-right">
                            <span class="inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                {{ ucfirst($this->run->status) }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-4">
                        <div class="flex justify-between text-xs text-blue-700 dark:text-blue-300">
                            <span>Đã xử lý: {{ number_format($this->run->processed_records) }} bản ghi</span>
                        </div>
                    </div>
                </div>
            @elseif ($this->run->isCompleted())
                <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-6 shadow-sm dark:border-emerald-900 dark:bg-emerald-950/20">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-emerald-900 dark:text-emerald-100">Gói export đã sẵn sàng!</h3>
                            <p class="mt-1 text-xs text-emerald-700 dark:text-emerald-300">
                                Run ID: <span class="font-mono">{{ $this->run->run_id }}</span> | Tổng bản ghi đã xuất: {{ number_format($this->run->processed_records) }}
                            </p>
                        </div>
                        <x-filament::button wire:click="downloadPackage" color="success" icon="heroicon-o-arrow-down-tray">
                            Tải file ZIP
                        </x-filament::button>
                    </div>
                </div>
            @elseif ($this->run->isFailed())
                <div class="rounded-xl border border-rose-200 bg-rose-50/50 p-6 shadow-sm dark:border-rose-900 dark:bg-rose-950/20">
                    <h3 class="text-base font-semibold text-rose-900 dark:text-rose-100">Xuất dữ liệu thất bại</h3>
                    <p class="mt-1 text-xs text-rose-700 dark:text-rose-300">
                        Lỗi: {{ $this->run->error_message }}
                    </p>
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>

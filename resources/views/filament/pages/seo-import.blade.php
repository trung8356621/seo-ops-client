<x-filament-panels::page>
    <div class="space-y-6">
        @if (! $this->activeImportRun())
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-lg font-medium text-gray-900 dark:text-white">Nhập dữ liệu SEO di động (Portable Data Import)</h2>
            <p class="mt-2 text-sm text-gray-500">
                Tác vụ chạy ngầm trên hàng đợi <code>client-transfer</code>. Toàn bộ tiến trình được theo dõi qua các lát cắt tiếp diễn độc lập và lưu vết trực tiếp vào cơ sở dữ liệu.
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

        @if ($inspectedFormat)
            <div class="rounded-xl border border-blue-200 bg-blue-50/50 p-6 shadow-sm dark:border-blue-900 dark:bg-blue-950/20">
                <h3 class="text-base font-semibold text-blue-900 dark:text-blue-100">Kết quả kiểm tra gói dữ liệu (Preflight):</h3>
                <dl class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-gray-500">Định dạng gói:</dt>
                        <dd class="font-medium text-gray-900 dark:text-white">{{ $inspectedFormat }} (v{{ $inspectedVersion }})</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Thời điểm export nguồn:</dt>
                        <dd class="font-medium text-gray-900 dark:text-white">{{ $inspectedExportedAt }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Kết nối database:</dt>
                        <dd class="font-medium {{ $connectionReady ? 'text-emerald-600' : 'text-rose-600' }}">
                            {{ $connectionReady ? 'Sẵn sàng' : 'Lỗi' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Schema/migrations:</dt>
                        <dd class="font-medium {{ $schemaReady ? 'text-emerald-600' : 'text-rose-600' }}">
                            {{ $schemaReady ? 'Sẵn sàng' : 'Chưa sẵn sàng' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Chế độ gói dữ liệu:</dt>
                        <dd class="font-medium {{ $isRetryData ? 'text-amber-600' : 'text-gray-900 dark:text-white' }}">
                            {{ $isRetryData ? 'Thử lại (Retry Data - Run ID: '.$originalImportRunId.')' : 'Toàn phần (Full Export)' }}
                        </dd>
                    </div>
                    @if (! $isRetryData)
                        <div>
                            <dt class="text-xs text-gray-500">Database business target trống:</dt>
                            <dd class="font-medium {{ ! $schemaReady ? 'text-gray-500' : ($targetEmpty ? 'text-emerald-600' : 'text-amber-600') }}">
                                {{ ! $schemaReady ? 'Chưa kiểm tra' : ($targetEmpty ? 'Có' : 'Không') }}
                            </dd>
                        </div>
                    @else
                        <div>
                            <dt class="text-xs text-gray-500">Kiểm tra database target:</dt>
                            <dd class="font-medium text-emerald-600">Cho phép dữ liệu hiện có (Retry Mode)</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-xs text-gray-500">Số tập dữ liệu (Datasets):</dt>
                        <dd class="font-medium text-gray-900 dark:text-white">{{ $inspectedDatasetCount }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500">Ước tính tổng bản ghi:</dt>
                        <dd class="font-medium text-gray-900 dark:text-white">{{ number_format($inspectedTotalRecords ?? 0) }}</dd>
                    </div>
                </dl>

                @if (! $schemaReady && ! empty($schemaErrors))
                    <div class="mt-4 rounded-lg bg-rose-100 p-3 text-xs text-rose-800 dark:bg-rose-900/40 dark:text-rose-200">
                        <strong>Schema chưa tương thích:</strong>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($schemaErrors as $schemaError)
                                <li>{{ $schemaError }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (! $isRetryData && $schemaReady && ! $targetEmpty)
                    <div class="mt-4 rounded-lg bg-amber-100 p-3 text-xs text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                        <strong>Lưu ý:</strong> Database SEO đích không trống. V1 chỉ hỗ trợ import vào database trống. Vui lòng làm trống database SEO trước khi import.
                    </div>
                @endif

                @if ($connectionReady && $schemaReady && ($isRetryData || $targetEmpty))
                    <div class="mt-6">
                        <x-filament::button wire:click="runImport" wire:loading.attr="disabled" color="success" icon="heroicon-o-play">
                            <span wire:loading.remove wire:target="runImport">{{ $isRetryData ? 'Bắt đầu Retry Import ngay' : 'Bắt đầu Import dữ liệu ngay' }}</span>
                            <span wire:loading wire:target="runImport">Đang đưa vào hàng đợi client-transfer...</span>
                        </x-filament::button>
                    </div>
                @endif
            </div>
        @endif
        @endif

        @if ($this->run)
            @if ($this->run->isRunning())
                <div wire:poll.2s class="rounded-xl border border-blue-200 bg-blue-50/50 p-6 shadow-sm dark:border-blue-900 dark:bg-blue-950/20">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-blue-900 dark:text-blue-100">Đang thực thi Import...</h3>
                            <p class="mt-1 text-xs text-blue-700 dark:text-blue-300">
                                Run ID: <span class="font-mono">{{ $this->run->run_id }}</span> | Giai đoạn: <span class="font-semibold">{{ $this->run->phase }}</span>
                                @if ($this->run->current_dataset)
                                    | Tập dữ liệu: <span class="font-semibold">{{ $this->run->current_dataset }}</span> (Phần: {{ $this->run->current_part ?? 0 }})
                                @endif
                            </p>
                        </div>
                        <div>
                            <span class="inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                {{ ucfirst($this->run->status) }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4 text-xs">
                        <div class="rounded bg-white p-2.5 shadow-xs dark:bg-gray-900">
                            <span class="text-gray-500">Đã xử lý:</span>
                            <span class="font-bold text-gray-900 dark:text-white">{{ number_format($this->run->processed_records) }} / {{ number_format($this->run->total_records) }}</span>
                        </div>
                        <div class="rounded bg-white p-2.5 shadow-xs dark:bg-gray-900">
                            <span class="text-gray-500">Thành công:</span>
                            <span class="font-bold text-emerald-600">{{ number_format($this->run->imported_count) }}</span>
                        </div>
                        <div class="rounded bg-white p-2.5 shadow-xs dark:bg-gray-900">
                            <span class="text-gray-500">Lỗi:</span>
                            <span class="font-bold text-rose-600">{{ number_format($this->run->failed_count) }}</span>
                        </div>
                        <div class="rounded bg-white p-2.5 shadow-xs dark:bg-gray-900">
                            <span class="text-gray-500">Bị chặn:</span>
                            <span class="font-bold text-amber-600">{{ number_format($this->run->blocked_count) }}</span>
                        </div>
                    </div>

                    @if (!empty($this->run->metadata['media_import']))
                        <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 text-xs">
                            <div class="rounded bg-white p-2.5 shadow-xs dark:bg-gray-900">
                                <span class="text-gray-500">Media restored:</span>
                                <span class="font-bold text-gray-900 dark:text-white">{{ number_format($this->run->metadata['media_import']['media_binaries_restored'] ?? 0) }}</span>
                            </div>
                            <div class="rounded bg-white p-2.5 shadow-xs dark:bg-gray-900">
                                <span class="text-gray-500">Media reused:</span>
                                <span class="font-bold text-gray-900 dark:text-white">{{ number_format($this->run->metadata['media_import']['media_binaries_reused'] ?? 0) }}</span>
                            </div>
                            <div class="rounded bg-white p-2.5 shadow-xs dark:bg-gray-900">
                                <span class="text-gray-500">Orphan files:</span>
                                <span class="font-bold text-gray-900 dark:text-white">{{ number_format($this->run->metadata['media_import']['orphan_files_restored'] ?? 0) }}</span>
                            </div>
                            <div class="rounded bg-white p-2.5 shadow-xs dark:bg-gray-900">
                                <span class="text-gray-500">Missing binaries:</span>
                                <span class="font-bold text-rose-600">{{ number_format($this->run->metadata['media_import']['missing_binaries'] ?? 0) }}</span>
                            </div>
                            <div class="rounded bg-white p-2.5 shadow-xs dark:bg-gray-900">
                                <span class="text-gray-500">Checksum failures:</span>
                                <span class="font-bold text-rose-600">{{ number_format($this->run->metadata['media_import']['checksum_failures'] ?? 0) }}</span>
                            </div>
                            <div class="rounded bg-white p-2.5 shadow-xs dark:bg-gray-900">
                                <span class="text-gray-500">Restored bytes:</span>
                                <span class="font-bold text-gray-900 dark:text-white">{{ number_format($this->run->metadata['media_import']['restored_bytes'] ?? 0) }}</span>
                            </div>
                        </div>
                    @endif

                    @if ($this->run->canCancel())
                        <div class="mt-6">
                            <x-filament::button
                                wire:click="stopImport"
                                wire:confirm="Stop this import? Records already imported will be kept. You can rollback this run afterward if needed."
                                wire:loading.attr="disabled"
                                color="danger"
                                icon="heroicon-o-stop"
                            >
                                Stop Import
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            @elseif ($this->run->isCompleted())
                <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Báo cáo kết quả Import (Run ID: {{ $this->run->run_id }})</h3>

                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
                        <div class="rounded-lg bg-emerald-50 p-3 text-center dark:bg-emerald-950/30">
                            <span class="block text-xs text-emerald-600 dark:text-emerald-400">Đã Import</span>
                            <span class="text-lg font-bold text-emerald-700 dark:text-emerald-300">{{ number_format($this->run->imported_count) }}</span>
                        </div>
                        <div class="rounded-lg bg-rose-50 p-3 text-center dark:bg-rose-950/30">
                            <span class="block text-xs text-rose-600 dark:text-rose-400">Thất bại</span>
                            <span class="text-lg font-bold text-rose-700 dark:text-rose-300">{{ number_format($this->run->failed_count) }}</span>
                        </div>
                        <div class="rounded-lg bg-amber-50 p-3 text-center dark:bg-amber-950/30">
                            <span class="block text-xs text-amber-600 dark:text-amber-400">Bị chặn (Parent lỗi)</span>
                            <span class="text-lg font-bold text-amber-700 dark:text-amber-300">{{ number_format($this->run->blocked_count) }}</span>
                        </div>
                        <div class="rounded-lg bg-sky-50 p-3 text-center dark:bg-sky-950/30">
                            <span class="block text-xs text-sky-600 dark:text-sky-400">Cảnh báo</span>
                            <span class="text-lg font-bold text-sky-700 dark:text-sky-300">{{ number_format($this->run->warnings_count) }}</span>
                        </div>
                        <div class="rounded-lg bg-purple-50 p-3 text-center dark:bg-purple-950/30">
                            <span class="block text-xs text-purple-600 dark:text-purple-400">Thiếu tham chiếu</span>
                            <span class="text-lg font-bold text-purple-700 dark:text-purple-300">{{ number_format($this->run->missing_refs_count) }}</span>
                        </div>
                    </div>

                    @if (!empty($this->run->metadata['media_import']))
                        <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 text-xs">
                            <div class="rounded bg-gray-50 p-2.5 dark:bg-gray-800">Media restored: <strong>{{ number_format($this->run->metadata['media_import']['media_binaries_restored'] ?? 0) }}</strong></div>
                            <div class="rounded bg-gray-50 p-2.5 dark:bg-gray-800">Media reused: <strong>{{ number_format($this->run->metadata['media_import']['media_binaries_reused'] ?? 0) }}</strong></div>
                            <div class="rounded bg-gray-50 p-2.5 dark:bg-gray-800">Orphan files: <strong>{{ number_format($this->run->metadata['media_import']['orphan_files_restored'] ?? 0) }}</strong></div>
                            <div class="rounded bg-gray-50 p-2.5 dark:bg-gray-800">Missing binaries: <strong>{{ number_format($this->run->metadata['media_import']['missing_binaries'] ?? 0) }}</strong></div>
                            <div class="rounded bg-gray-50 p-2.5 dark:bg-gray-800">Checksum failures: <strong>{{ number_format($this->run->metadata['media_import']['checksum_failures'] ?? 0) }}</strong></div>
                            <div class="rounded bg-gray-50 p-2.5 dark:bg-gray-800">Restored bytes: <strong>{{ number_format($this->run->metadata['media_import']['restored_bytes'] ?? 0) }}</strong></div>
                        </div>
                    @endif

                    @if ($this->run->retry_package_path)
                        <div class="mt-6 flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50/50 p-4 dark:border-amber-900 dark:bg-amber-950/20">
                            <div>
                                <h4 class="text-sm font-semibold text-amber-900 dark:text-amber-100">Gói kiểm dịch & thử lại (Quarantine Retry Package)</h4>
                                <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                    Bao gồm danh sách lỗi và các bản ghi thất bại kèm phụ thuộc để khắc phục và tái import.
                                </p>
                            </div>
                            <x-filament::button wire:click="downloadRetryPackage" color="warning" icon="heroicon-o-arrow-down-tray">
                                Tải gói Retry ZIP
                            </x-filament::button>
                        </div>
                    @endif
                    <div class="mt-6">
                        <x-filament::button
                            wire:click="rollbackImport"
                            wire:confirm="Rollback dữ liệu đã import bởi run này?"
                            wire:loading.attr="disabled"
                            color="danger"
                            icon="heroicon-o-arrow-uturn-left"
                        >
                            Rollback Import
                        </x-filament::button>
                    </div>
                </div>
            @elseif ($this->run->isCancelled())
                <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Import đã dừng (Cancelled)</h3>
                            <p class="mt-1 text-xs text-gray-500">
                                Run ID: <span class="font-mono">{{ $this->run->run_id }}</span> | Dữ liệu đã import được giữ nguyên. Bạn có thể Rollback bên dưới nếu cần.
                            </p>
                        </div>
                        <div>
                            <span class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                                Cancelled
                            </span>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4 text-xs">
                        <div class="rounded bg-gray-50 p-2.5 shadow-xs dark:bg-gray-800">
                            <span class="text-gray-500">Đã xử lý:</span>
                            <span class="font-bold text-gray-900 dark:text-white">{{ number_format($this->run->processed_records) }} / {{ number_format($this->run->total_records) }}</span>
                        </div>
                        <div class="rounded bg-gray-50 p-2.5 shadow-xs dark:bg-gray-800">
                            <span class="text-gray-500">Đã Import:</span>
                            <span class="font-bold text-emerald-600">{{ number_format($this->run->imported_count) }}</span>
                        </div>
                        <div class="rounded bg-gray-50 p-2.5 shadow-xs dark:bg-gray-800">
                            <span class="text-gray-500">Lỗi:</span>
                            <span class="font-bold text-rose-600">{{ number_format($this->run->failed_count) }}</span>
                        </div>
                        <div class="rounded bg-gray-50 p-2.5 shadow-xs dark:bg-gray-800">
                            <span class="text-gray-500">Bị chặn:</span>
                            <span class="font-bold text-amber-600">{{ number_format($this->run->blocked_count) }}</span>
                        </div>
                    </div>

                    @if ($this->run->canRollback())
                        <div class="mt-6">
                            <x-filament::button
                                wire:click="rollbackImport"
                                wire:confirm="Rollback dữ liệu đã import bởi run này?"
                                wire:loading.attr="disabled"
                                color="danger"
                                icon="heroicon-o-arrow-uturn-left"
                            >
                                Rollback Import
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            @elseif ($this->run->isFailed())
                <div class="rounded-xl border border-rose-200 bg-rose-50/50 p-6 shadow-sm dark:border-rose-900 dark:bg-rose-950/20">
                    <h3 class="text-base font-semibold text-rose-900 dark:text-rose-100">Import thất bại</h3>
                    <p class="mt-1 text-xs text-rose-700 dark:text-rose-300">
                        Lỗi: {{ $this->run->error_message }}
                    </p>
                    @if ($this->run->canRollback())
                        <div class="mt-4">
                            <x-filament::button
                                wire:click="rollbackImport"
                                wire:confirm="Rollback dữ liệu đã import bởi run này?"
                                wire:loading.attr="disabled"
                                color="danger"
                                icon="heroicon-o-arrow-uturn-left"
                            >
                                Rollback Import
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            @elseif ($this->run->isRolledBack())
                <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-6 shadow-sm dark:border-emerald-900 dark:bg-emerald-950/20">
                    <h3 class="text-base font-semibold text-emerald-900 dark:text-emerald-100">Rollback hoàn tất</h3>
                    <p class="mt-1 text-xs text-emerald-700 dark:text-emerald-300">
                        Dữ liệu được tạo bởi run {{ $this->run->run_id }} đã được gỡ bỏ.
                    </p>
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>

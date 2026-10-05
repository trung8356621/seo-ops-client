<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-lg font-medium text-gray-900 dark:text-white">Xuất dữ liệu SEO di động (Portable Data Package)</h2>
            <p class="mt-2 text-sm text-gray-500">
                Gói dữ liệu chuyển giao bao gồm toàn bộ thực thể nghiệp vụ SEO (Từ khóa, Phân loại, Chủ đề, Bài viết, Đính kèm hình ảnh, Liên kết và Kế hoạch nội dung).
                Không chứa thông tin mật, service keys hay credential nhạy cảm.
            </p>

            <div class="mt-6 flex items-center gap-3">
                <x-filament::button wire:click="runExport" wire:loading.attr="disabled" color="primary" icon="heroicon-o-arrow-up-tray">
                    <span wire:loading.remove wire:target="runExport">Bắt đầu xuất dữ liệu</span>
                    <span wire:loading wire:target="runExport">Đang tạo gói export...</span>
                </x-filament::button>

                <a href="{{ \App\Filament\Pages\ServiceStatusOverview::getUrl() }}" class="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                    Quay lại Dịch vụ
                </a>
            </div>
        </div>

        @if ($exportResult)
            <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-6 shadow-sm dark:border-emerald-900 dark:bg-emerald-950/20">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-emerald-900 dark:text-emerald-100">Gói export đã sẵn sàng!</h3>
                        <p class="mt-1 text-xs text-emerald-700 dark:text-emerald-300">
                            Thời điểm: {{ $exportResult['exported_at'] }} | Dung lượng: {{ number_format($exportResult['file_size']) }} bytes
                        </p>
                    </div>
                    <x-filament::button wire:click="downloadPackage" color="success" icon="heroicon-o-arrow-down-tray">
                        Tải file ZIP
                    </x-filament::button>
                </div>

                <div class="mt-6 border-t border-emerald-200/60 pt-4 dark:border-emerald-800/60">
                    <h4 class="text-xs font-semibold uppercase tracking-wider text-emerald-800 dark:text-emerald-200">Chi tiết số lượng bản ghi:</h4>
                    <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
                        @foreach ($exportResult['counts'] as $dataset => $count)
                            <div class="rounded-lg border border-emerald-100 bg-white p-3 text-sm shadow-xs dark:border-gray-800 dark:bg-gray-900">
                                <span class="block text-xs text-gray-500">{{ $dataset }}</span>
                                <span class="text-base font-bold text-gray-900 dark:text-white">{{ number_format($count) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>

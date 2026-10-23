<x-filament-panels::page>
    @php
        $opsReport = (isset($this) && method_exists($this, 'opsServerConnectionReport'))
            ? $this->opsServerConnectionReport()
            : app(\App\Filament\Pages\ServiceStatusOverview::class)->opsServerConnectionReport();
        $rows = (isset($this) && method_exists($this, 'serviceCards'))
            ? $this->serviceCards()
            : app(\App\Filament\Pages\ServiceStatusOverview::class)->serviceCards();
    @endphp

    <div class="mb-4 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="min-w-0">
            <div class="font-semibold text-gray-900 dark:text-white">Bản quyền (H1)</div>
            <div class="truncate text-xs text-gray-500">{{ $opsReport['status_label'] }}</div>
        </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($rows as $row)
                <div class="flex flex-wrap items-center gap-3 px-3 py-2.5" wire:key="service-row-{{ $row['slug'] }}">
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ $row['name'] }}</div>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                            <span>{{ $row['entitlement_label'] }}</span>
                            <span class="text-gray-300 dark:text-gray-600">·</span>
                            <span>{{ $row['db_label'] }}</span>
                        </div>
                    </div>
                    <span @class([
                        'inline-flex shrink-0 rounded px-2 py-0.5 text-xs font-medium',
                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $row['status'] === 'ready',
                        'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => in_array($row['status'], ['needs_config', 'suspended'], true),
                        'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' => $row['status'] === 'connection_error',
                        'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => $row['status'] === 'not_entitled',
                    ])>
                        {{ $row['status_label'] }}
                    </span>
                    <a
                        href="{{ $row['setup_url'] }}"
                        class="inline-flex shrink-0 items-center rounded-md border border-gray-300 px-2.5 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800"
                    >
                        Cấu hình
                    </a>
                </div>
            @empty
                <div class="px-3 py-4 text-sm text-gray-500">Chưa có service trong catalog.</div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>

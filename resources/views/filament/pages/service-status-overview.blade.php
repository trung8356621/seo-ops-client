<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($this->serviceCards() as $card)
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $card['name'] }}</h3>
                        <p class="mt-1 text-xs text-gray-500">slug: {{ $card['slug'] }}</p>
                    </div>
                    <span @class([
                        'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $card['active'],
                        'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => ! $card['active'] && $card['exists'],
                        'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => ! $card['exists'],
                    ])>
                        @if (! $card['exists'])
                            Chưa provision
                        @elseif ($card['active'])
                            Active
                        @else
                            Inactive
                        @endif
                    </span>
                </div>

                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between gap-2">
                        <dt class="text-gray-500">Service key</dt>
                        <dd class="font-medium">{{ $card['key_label'] }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-gray-500">DB connection</dt>
                        <dd class="font-medium">{{ $card['db_connection'] }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-gray-500">Trạng thái DB</dt>
                        <dd class="font-medium text-right">{{ $card['db_label'] }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-gray-500">Nguồn</dt>
                        <dd class="font-medium">{{ $card['connection_source'] ?? '—' }}</dd>
                    </div>
                </dl>

                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ $card['open_url'] }}" class="inline-flex items-center rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-500">
                        Mở {{ $card['name'] }}
                    </a>
                    <a href="{{ $card['setup_url'] }}" class="inline-flex items-center rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                        Cấu hình
                    </a>
                </div>
            </div>
        @endforeach
    </div>

    <p class="mt-4 text-xs text-gray-500">
        Chỉ ops-server provision Service. Client không Activate / Deactivate / Install.
        Xem <code>docs/architecture/SERVICE_ARCHITECTURE.md</code>.
    </p>
</x-filament-panels::page>

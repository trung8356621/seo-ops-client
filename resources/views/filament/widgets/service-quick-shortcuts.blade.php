<div class="fi-wi-widget">
    <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-800">
            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Dịch vụ</h2>
            <p class="mt-0.5 text-xs text-gray-500">Lối tắt nhanh SEO &amp; Seeding</p>
        </div>

        <div class="grid gap-3 p-4 sm:grid-cols-2">
            @forelse ($this->cards() as $card)
                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $card['name'] }}</h3>
                            <p class="mt-1 text-xs text-gray-500">{{ $card['key_label'] }} · {{ $card['db_label'] }}</p>
                        </div>
                        <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                            {{ $card['badge'] }}
                        </span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a
                            href="{{ $card['open_url'] }}"
                            class="inline-flex items-center rounded-lg bg-primary-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-primary-500"
                        >
                            Mở {{ $card['name'] }}
                        </a>
                        <a
                            href="{{ $card['setup_url'] }}"
                            class="inline-flex items-center rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800"
                        >
                            Cấu hình
                        </a>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500 sm:col-span-2">Chưa có dịch vụ khả dụng.</p>
            @endforelse
        </div>
    </div>
</div>

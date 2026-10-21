<x-filament-panels::page>
    @php
        $opsReport = (isset($this) && method_exists($this, 'opsServerConnectionReport'))
            ? $this->opsServerConnectionReport()
            : app(\App\Filament\Pages\ServiceStatusOverview::class)->opsServerConnectionReport();
    @endphp

    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900 mb-6">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-4 dark:border-gray-800">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary-50 dark:bg-primary-950/50">
                    <x-heroicon-o-server-stack class="h-6 w-6 text-primary-600 dark:text-primary-400" />
                </div>
                <div>
                    <h2 class="text-base font-bold text-gray-900 dark:text-white">OPS-SERVER Control &amp; License Connection</h2>
                    <p class="text-xs text-gray-500">Giao thức H1 (<code>seo-ops-license/1</code>) &amp; Golden Sync (<code>seo-ops-config/1</code>)</p>
                </div>
            </div>
            <div>
                @if ($opsReport['status_badge'] === 'emerald')
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                        {{ $opsReport['status_label'] }}
                    </span>
                @elseif ($opsReport['status_badge'] === 'amber')
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                        <span class="h-2 w-2 rounded-full bg-amber-500"></span>
                        {{ $opsReport['status_label'] }}
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                        <span class="h-2 w-2 rounded-full bg-gray-400"></span>
                        {{ $opsReport['status_label'] }}
                    </span>
                @endif
            </div>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/50">
                <span class="text-xs text-gray-500">Installation ID</span>
                <p class="mt-1 font-mono text-sm font-semibold text-gray-900 dark:text-white truncate" title="{{ $opsReport['installation_id'] }}">
                    {{ $opsReport['installation_id'] }}
                </p>
                <span class="mt-1 block text-xs text-gray-400">Machine Credential: {{ $opsReport['credential_version'] }}</span>
            </div>

            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/50">
                <span class="text-xs text-gray-500">Đồng bộ bản quyền (Reconcile)</span>
                <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white truncate">
                    Rev {{ $opsReport['entitlement_revision'] }}
                </p>
                <span class="mt-1 block text-xs text-gray-400" title="{{ $opsReport['last_reconciled'] }}">
                    Lần cuối: {{ $opsReport['last_reconciled'] }}
                </span>
            </div>

            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/50">
                <span class="text-xs text-gray-500">Giấy phép SEO &amp; Seeding</span>
                <div class="mt-1 flex items-center justify-between text-xs">
                    <span class="font-medium text-gray-700 dark:text-gray-300">SEO:</span>
                    <span @class([
                        'font-semibold',
                        'text-emerald-600 dark:text-emerald-400' => $opsReport['seo_status'] === 'Active',
                        'text-red-500' => $opsReport['seo_status'] !== 'Active',
                    ])>
                        {{ $opsReport['seo_status'] }} ({{ $opsReport['seo_quota'] }})
                    </span>
                </div>
                <div class="mt-0.5 flex items-center justify-between text-xs">
                    <span class="font-medium text-gray-700 dark:text-gray-300">Seeding:</span>
                    <span @class([
                        'font-semibold',
                        'text-emerald-600 dark:text-emerald-400' => $opsReport['seeding_status'] === 'Active',
                        'text-red-500' => $opsReport['seeding_status'] !== 'Active',
                    ])>
                        {{ $opsReport['seeding_status'] }}
                    </span>
                </div>
            </div>

            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/50">
                <span class="text-xs text-gray-500">Golden Configuration</span>
                <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                    v{{ $opsReport['golden_version'] }}
                </p>
                <span @class([
                    'mt-1 inline-flex rounded px-1.5 py-0.5 text-xs font-medium',
                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => $opsReport['golden_status'] === 'in_sync',
                    'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' => $opsReport['golden_status'] === 'customized',
                    'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300' => ! in_array($opsReport['golden_status'], ['in_sync', 'customized'], true),
                ])>
                    {{ $opsReport['golden_status'] }}
                </span>
            </div>
        </div>

        @if (! empty($opsReport['last_error']))
            <div class="mt-3 rounded-lg border border-red-200 bg-red-50 p-2.5 text-xs text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300">
                <span class="font-semibold">Lỗi gần nhất:</span> {{ $opsReport['last_error'] }}
            </div>
        @endif
    </div>

    @php
        $cards = (isset($this) && method_exists($this, 'serviceCards'))
            ? $this->serviceCards()
            : app(\App\Filament\Pages\ServiceStatusOverview::class)->serviceCards();
    @endphp
    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($cards as $card)
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
                    @if ($card['slug'] === 'seo')
                        <a href="{{ \App\Filament\Pages\SeoExport::getUrl() }}" class="inline-flex items-center rounded-lg border border-emerald-300 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 dark:border-emerald-600 dark:text-emerald-300 dark:hover:bg-emerald-900/30">
                            Export Data
                        </a>
                        <a href="{{ \App\Filament\Pages\SeoImport::getUrl() }}" class="inline-flex items-center rounded-lg border border-blue-300 px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50 dark:border-blue-600 dark:text-blue-300 dark:hover:bg-blue-900/30">
                            Import Data
                        </a>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <p class="mt-4 text-xs text-gray-500">
        Chỉ ops-server provision Service. Client không Activate / Deactivate / Install.
        Xem <code>docs/architecture/SERVICE_ARCHITECTURE.md</code>.
    </p>
</x-filament-panels::page>

@php
    $canUsage = $this->canViewUsageOverview();
    $chartPayload = $canUsage ? $this->usageChartPayload() : [];
    $alerts = $canUsage ? $this->operationalAlerts() : [];
    $shortcutCards = $this->serviceShortcutCards();
@endphp

<x-filament-panels::page>
    @php
        $dashboardChartsVite = '';
        try {
            $dashboardChartsVite = (string) app(\Illuminate\Foundation\Vite::class)([
                'resources/css/filament/admin/theme.css',
                'resources/js/admin-dashboard-usage-charts.js',
            ]);
        } catch (\Throwable) {
            $dashboardChartsVite = '';
        }
    @endphp
    {!! $dashboardChartsVite !!}

    <div class="ops-dashboard space-y-4">
        {{-- Quick Setup Onboarding Welcome Card --}}
        @if ($this->shouldShowQuickSetupWelcome())
            <div class="rounded-xl border border-primary-500/30 bg-gradient-to-r from-primary-500/10 via-primary-500/5 to-transparent p-5 shadow-sm">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-sparkles" class="h-6 w-6 text-primary-500" />
                            <h2 class="text-lg font-bold text-gray-950 dark:text-white">Chào mừng đến SEO-OPS</h2>
                        </div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Hoàn thành thiết lập để bắt đầu sử dụng dịch vụ.
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <x-filament::button
                            tag="a"
                            href="{{ \App\Filament\Pages\QuickSetupWizard::getUrl() }}"
                            icon="heroicon-m-arrow-right"
                            icon-position="after"
                        >
                            Bắt đầu thiết lập
                        </x-filament::button>
                    </div>
                </div>
            </div>
        @elseif ($this->hasCompletedQuickSetup())
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-xs text-gray-600 shadow-sm dark:border-white/10 dark:bg-gray-900 dark:text-gray-400">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-m-check-circle" class="h-4 w-4 text-emerald-500" />
                    <span>Hệ thống đã hoàn tất thiết lập ban đầu.</span>
                </div>
                <a href="{{ \App\Filament\Pages\QuickSetupWizard::getUrl() }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                    Xem lại thiết lập &rarr;
                </a>
            </div>
        @endif

        @if ($canUsage)
            {{-- ROW 1: compact toolbar (title/subheading come from Filament page header) --}}
            <div class="flex flex-wrap items-center justify-end gap-2">
                @include('seo-content-ai::filament.pages.partials.ai-usage.filters-toolbar')
            </div>

            {{-- ROW 2: KPI --}}
            @include('seo-content-ai::filament.pages.partials.ai-usage.kpi-cards')

            {{-- ROW 3: Wallet | Alerts --}}
            <div class="ops-split-grid grid grid-cols-1 gap-4 lg:grid-cols-12">
                <div class="ops-split-main">
                    @include('seo-content-ai::filament.pages.partials.ai-usage.provider-wallet', [
                        'showManageLink' => true,
                        'manageProvidersUrl' => $this->getManageProvidersUrl(),
                        'compact' => true,
                    ])
                </div>
                <div class="ops-split-side">
                    @include('seo-content-ai::filament.pages.partials.ai-usage.alerts-panel', [
                        'alerts' => $alerts,
                        'scrollTarget' => '#dashboard-usage-detail',
                    ])
                </div>
            </div>

            @include('seo-content-ai::filament.pages.partials.ai-usage.threshold-modal')

            {{-- ROW 4: Trend chart | Distribution --}}
            <div class="ops-split-grid grid grid-cols-1 gap-4 lg:grid-cols-12">
                <div class="ops-split-main">
                    @include('seo-content-ai::filament.pages.partials.ai-usage.daily-trend', [
                        'useApex' => true,
                    ])
                </div>
                <div class="ops-split-side">
                    @include('seo-content-ai::filament.pages.partials.ai-usage.module-allocation', [
                        'useApex' => true,
                        'scrollTarget' => '#dashboard-usage-detail',
                    ])
                </div>
            </div>

            {{-- Shared chart payload (ApexCharts) --}}
            <script type="application/json" id="admin-dashboard-usage-charts-payload">
                @json($chartPayload)
            </script>

            {{-- ROW 5: Detail table | Services shortcuts (real panel; no fake activity) --}}
            <div class="ops-split-grid grid grid-cols-1 gap-4 lg:grid-cols-12" id="dashboard-usage-detail">
                <div class="ops-split-main">
                    @include('seo-content-ai::filament.pages.partials.ai-usage.breakdown-table', [
                        'showRatio' => true,
                        'compact' => true,
                        'scrollTarget' => '#dashboard-usage-detail',
                    ])
                </div>
                <div class="ops-split-side">
                    @include('seo-content-ai::filament.pages.partials.ai-usage.services-panel', [
                        'cards' => $shortcutCards,
                    ])
                </div>
            </div>
        @else
            @if (count($shortcutCards) > 0)
                @include('seo-content-ai::filament.pages.partials.ai-usage.services-panel', [
                    'cards' => $shortcutCards,
                ])
            @endif
        @endif
    </div>
</x-filament-panels::page>

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
            $dashboardChartsVite = (string) app(\Illuminate\Foundation\Vite::class)(['resources/js/admin-dashboard-usage-charts.js']);
        } catch (\Throwable) {
            $dashboardChartsVite = '';
        }
    @endphp
    {!! $dashboardChartsVite !!}

    <div class="ops-dashboard space-y-4">
        @if ($canUsage)
            {{-- ROW 1: compact toolbar (title/subheading come from Filament page header) --}}
            <div class="flex flex-wrap items-center justify-end gap-2">
                @include('seo-content-ai::filament.pages.partials.ai-usage.filters-toolbar')
            </div>

            {{-- ROW 2: KPI --}}
            @include('seo-content-ai::filament.pages.partials.ai-usage.kpi-cards')

            {{-- ROW 3: Wallet | Alerts --}}
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
                <div class="lg:col-span-8">
                    @include('seo-content-ai::filament.pages.partials.ai-usage.provider-wallet', [
                        'showManageLink' => true,
                        'manageProvidersUrl' => $this->getManageProvidersUrl(),
                        'compact' => true,
                    ])
                </div>
                <div class="lg:col-span-4">
                    @include('seo-content-ai::filament.pages.partials.ai-usage.alerts-panel', [
                        'alerts' => $alerts,
                        'scrollTarget' => '#dashboard-usage-detail',
                    ])
                </div>
            </div>

            @include('seo-content-ai::filament.pages.partials.ai-usage.threshold-modal')

            {{-- ROW 4: Trend chart | Distribution --}}
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
                <div class="lg:col-span-8">
                    @include('seo-content-ai::filament.pages.partials.ai-usage.daily-trend', [
                        'useApex' => true,
                    ])
                </div>
                <div class="lg:col-span-4">
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
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-12" id="dashboard-usage-detail">
                <div class="lg:col-span-8">
                    @include('seo-content-ai::filament.pages.partials.ai-usage.breakdown-table', [
                        'showRatio' => true,
                        'compact' => true,
                        'scrollTarget' => '#dashboard-usage-detail',
                    ])
                </div>
                <div class="lg:col-span-4">
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

<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\ServiceQuickShortcutsWidget;
use App\Services\Ai\AiOperationalAlertsService;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;
use Omnichannel\Addons\AiPrompt\Filament\Concerns\InteractsWithAiUsageOverview;
use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Admin operational overview — Usage presentation lives here only (no /admin/usage page).
 */
class Dashboard extends BaseDashboard
{
    use InteractsWithAiUsageOverview;

    protected static string $view = 'filament.pages.dashboard';

    public function getTitle(): string|Htmlable
    {
        return 'Dashboard';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Tổng quan hệ thống SEO-OPS';
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        // Shortcuts are rendered inline in the dashboard grid (row 5 right).
        return [];
    }

    /**
     * @return int|array<string, int|null>
     */
    public function getColumns(): int|array
    {
        return 1;
    }

    public function canViewUsageOverview(): bool
    {
        $user = auth()->user();
        if ($user instanceof \App\Models\User
            && in_array((string) $user->role, [\App\Models\User::ROLE_OWNER, \App\Models\User::ROLE_ADMIN], true)
        ) {
            return true;
        }

        return class_exists(SeoAccessControl::class) && SeoAccessControl::canAccessManagerFeatures();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function operationalAlerts(): array
    {
        return app(AiOperationalAlertsService::class)->getActiveAlerts();
    }

    /**
     * Chart payload for ApexCharts (trend + donut). Real aggregation only.
     *
     * @return array<string, mixed>
     */
    public function usageChartPayload(): array
    {
        $trend = $this->tokenDailyTrend();
        $summary = $this->tokenSummary();
        $table = $this->tokenTableData();
        $total = max(0, (int) ($summary['total_tokens'] ?? 0));

        $palette = [
            'seo' => '#10b981',
            'seeding' => '#8b5cf6',
            'unknown' => '#94a3b8',
        ];
        $fallback = ['#0ea5e9', '#f59e0b', '#ef4444', '#14b8a6', '#6366f1'];

        $slices = [];
        foreach ($table as $i => $row) {
            $tokens = (int) ($row['total_tokens'] ?? 0);
            if ($tokens <= 0) {
                continue;
            }
            $key = (string) ($row['key'] ?? '');
            $slices[] = [
                'key' => $key,
                'name' => (string) ($row['name'] ?? $key),
                'tokens' => $tokens,
                'pct' => $total > 0 ? round(($tokens / $total) * 100, 1) : 0.0,
                'color' => $palette[$key] ?? $fallback[$i % count($fallback)],
            ];
        }

        return [
            'labels' => $trend['labels'] ?? [],
            'dates' => $trend['dates'] ?? [],
            'seo_series' => $trend['seo_series'] ?? [],
            'seeding_series' => $trend['seeding_series'] ?? [],
            'total_series' => $trend['total_series'] ?? [],
            'max_tokens' => (int) ($trend['max_tokens'] ?? 0),
            'slices' => $slices,
            'center_total' => $total,
        ];
    }

    public function getManageProvidersUrl(): string
    {
        if (class_exists(AiConnectionResource::class)) {
            try {
                return AiConnectionResource::getUrl('index');
            } catch (\Throwable) {
                // fall through
            }
        }

        return url('/admin/ai-connections');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function serviceShortcutCards(): array
    {
        if (! ServiceQuickShortcutsWidget::canView()) {
            return [];
        }

        return (new ServiceQuickShortcutsWidget())->cards();
    }
}

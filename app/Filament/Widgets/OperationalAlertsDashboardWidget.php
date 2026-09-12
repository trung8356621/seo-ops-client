<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\User;
use App\Services\Ai\AiOperationalAlertsService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

final class OperationalAlertsDashboardWidget extends Widget
{
    protected static ?int $sort = -5;

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'md' => 1,
    ];

    protected static string $view = 'filament.widgets.operational-alerts-dashboard-widget';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && in_array((string) $user->role, [User::ROLE_OWNER, User::ROLE_ADMIN], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function alerts(): array
    {
        return app(AiOperationalAlertsService::class)->getActiveAlerts();
    }
}

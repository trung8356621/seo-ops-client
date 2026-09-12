<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\ApiConnection;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;
use Omnichannel\Addons\AiPrompt\Services\Wallet\AiProviderWalletService;

final class AiOperationalAlertsService
{
    public function __construct(
        private readonly ?AiProviderWalletService $walletService = null,
    ) {}

    private function wallet(): AiProviderWalletService
    {
        return $this->walletService ?? app(AiProviderWalletService::class);
    }

    /**
     * Active operational alerts in priority order (Critical -> Warning -> Newest).
     *
     * @return list<array{
     *     id: string,
     *     severity: 'critical'|'warning',
     *     title: string,
     *     message: string,
     *     action_label: ?string,
     *     action_url: ?string,
     *     detected_at: ?Carbon,
     *     detected_at_humans: ?string
     * }>
     */
    public function getActiveAlerts(): array
    {
        $connections = ApiConnection::query()
            ->where('status', 'active')
            ->get();

        $alerts = [];
        $usageUrl = $this->getAiCenterUsageUrl();

        foreach ($connections as $conn) {
            $provider = (string) ($conn->provider ?? '');

            // Bỏ qua provider không hỗ trợ balance API
            if (! $this->wallet()->supportsBalance($provider)) {
                continue;
            }

            $currency = (string) ($conn->currency ?: 'USD');
            $sym = $currency === 'USD' ? '$' : $currency . ' ';
            $threshold = (float) ($conn->balance_warning_threshold ?? 5.0);

            // 1. Low balance alert (khi balance <= threshold)
            if ($conn->balance !== null && (float) $conn->balance <= $threshold) {
                $bal = (float) $conn->balance;
                $isCritical = $bal <= 0.0;
                $checkedAt = $conn->balance_checked_at ? Carbon::parse($conn->balance_checked_at) : null;

                $alerts[] = [
                    'id' => 'low_balance_'.$conn->id,
                    'type' => 'low_balance',
                    'severity' => $isCritical ? 'critical' : 'warning',
                    'title' => sprintf('Số dư %s thấp', $conn->name),
                    'message' => sprintf(
                        '%s chỉ còn %s%s — thấp hơn ngưỡng %s%s.',
                        $conn->name,
                        $sym,
                        number_format($bal, 2),
                        $sym,
                        number_format($threshold, 2)
                    ),
                    'action_label' => 'Kiểm tra ví',
                    'action_url' => $usageUrl,
                    'detected_at' => $checkedAt,
                    'detected_at_humans' => $checkedAt?->diffForHumans(),
                ];

                // Nếu đã báo low balance thì không cần chồng thêm check failed cho cùng connection
                continue;
            }

            // 2. Balance stale/check failed (kéo dài >= 6 giờ)
            if ((string) $conn->balance_status === 'check_failed') {
                $checkedAt = $conn->balance_checked_at ? Carbon::parse($conn->balance_checked_at) : null;
                $hours = $checkedAt ? (int) $checkedAt->diffInHours(now()) : 6;

                if ($hours >= 6) {
                    $alerts[] = [
                        'id' => 'stale_balance_'.$conn->id,
                        'type' => 'balance_stale',
                        'severity' => 'warning',
                        'title' => sprintf('Không thể cập nhật số dư %s', $conn->name),
                        'message' => sprintf('Không thể cập nhật số dư %s trong %d giờ.', $conn->name, $hours),
                        'action_label' => 'Kiểm tra ví',
                        'action_url' => $usageUrl,
                        'detected_at' => $checkedAt,
                        'detected_at_humans' => $checkedAt?->diffForHumans(),
                    ];
                }
            }
        }

        // Sắp xếp: critical trước, warning sau; cùng severity thì mới nhất lên trước
        usort($alerts, function (array $a, array $b): int {
            if ($a['severity'] !== $b['severity']) {
                return $a['severity'] === 'critical' ? -1 : 1;
            }

            $timeA = $a['detected_at'] instanceof Carbon ? $a['detected_at']->timestamp : 0;
            $timeB = $b['detected_at'] instanceof Carbon ? $b['detected_at']->timestamp : 0;

            return $timeB <=> $timeA;
        });

        return $alerts;
    }

    public function countActiveAlerts(): int
    {
        return count($this->getActiveAlerts());
    }

    public function getAiCenterUsageUrl(): string
    {
        if (Route::has('filament.admin.pages.seo-settings-ai-center')) {
            return route('filament.admin.pages.seo-settings-ai-center', ['tab' => 'usage']);
        }

        return url('/admin/settings/ai-center?tab=usage');
    }
}

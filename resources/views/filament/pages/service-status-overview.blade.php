<x-filament-panels::page>
    <div wire:init="mountAutoReconcile"></div>

    @php
        $opsReport = $this->opsServerConnectionReport();
        $rows = $this->serviceCards();
        $activeNotifications = $this->activeClientNotifications();
        $notificationHistory = $this->clientNotificationHistory();
    @endphp

    <div class="mb-4 rounded-lg border border-gray-200 bg-white px-3 py-3 text-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="font-semibold text-gray-900 dark:text-white">Bản quyền (H1)</div>
                <div class="mt-0.5 text-xs text-gray-500">{{ $opsReport['status_label'] }}</div>
                <div class="mt-2 grid gap-1 text-xs text-gray-600 dark:text-gray-300 sm:grid-cols-2">
                    <div>Installation: <span class="font-mono">{{ $opsReport['installation_id'] }}</span></div>
                    <div>Revision (Client): <span class="font-mono">{{ $opsReport['entitlement_revision'] }}</span></div>
                    <div>OPS URL: <span class="truncate font-mono">{{ $opsReport['control_server_url'] }}</span></div>
                    <div>Đồng bộ gần nhất: {{ $opsReport['last_reconciled'] }}</div>
                    <div>SEO (snapshot): {{ $opsReport['seo_status'] }}</div>
                    <div>Seeding (snapshot): {{ $opsReport['seeding_status'] }}</div>
                    <div>Social Automation (snapshot): {{ $opsReport['social_automation_status'] }}</div>
                </div>
                @if (! empty($opsReport['last_error']))
                    <div class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $opsReport['last_error'] }}</div>
                @endif
            </div>
            <button
                type="button"
                wire:click="reconcileLicense"
                wire:loading.attr="disabled"
                wire:target="reconcileLicense,mountAutoReconcile"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-gray-300 px-2.5 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800"
            >
                <span wire:loading.remove wire:target="reconcileLicense,mountAutoReconcile">Đồng bộ H1</span>
                <span wire:loading wire:target="reconcileLicense,mountAutoReconcile" class="inline-flex items-center gap-1">
                    <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                    Đang đồng bộ…
                </span>
            </button>
        </div>
        <p class="mt-2 text-[11px] text-gray-400">
            H1 đã kết nối ≠ dịch vụ đã được cấp quyền. Thông báo dưới đây do OPS gửi qua H1 (không chỉnh sửa được tại Client).
        </p>
    </div>

    @if (count($activeNotifications) > 0)
        <div class="mb-4 space-y-2">
            @foreach ($activeNotifications as $ntf)
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                    <div class="font-semibold">
                        {{ strtoupper((string) ($ntf['action'] ?? '')) }}
                        @if (! empty($ntf['service_key']))
                            · {{ $ntf['service_key'] }}
                        @endif
                        @if (! empty($ntf['entitlement_revision']))
                            · rev {{ $ntf['entitlement_revision'] }}
                        @endif
                    </div>
                    <div class="mt-0.5">{{ $ntf['message'] ?? '' }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <div
        class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900"
        wire:loading.class="opacity-60"
        wire:target="reconcileLicense,mountAutoReconcile"
    >
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
                        @if (! empty($row['client_message']) && in_array($row['entitlement_state'] ?? '', ['suspended', 'not_entitled'], true))
                            <div class="mt-1 text-xs text-amber-700 dark:text-amber-300">{{ $row['client_message'] }}</div>
                        @endif
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

    @if (count($notificationHistory) > 0)
        <div class="mt-4 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-900">
            <div class="mb-1 font-semibold text-gray-900 dark:text-white">Lịch sử thông báo H1</div>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($notificationHistory as $ntf)
                    <li class="py-1.5 text-gray-600 dark:text-gray-300">
                        <span class="font-medium text-gray-800 dark:text-gray-100">{{ $ntf['action'] ?? '' }}</span>
                        @if (! empty($ntf['service_key'])) · {{ $ntf['service_key'] }} @endif
                        @if (! empty($ntf['entitlement_revision'])) · rev {{ $ntf['entitlement_revision'] }} @endif
                        @if (! empty($ntf['issued_at'])) · {{ $ntf['issued_at'] }} @endif
                        <div>{{ $ntf['message'] ?? '' }}</div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</x-filament-panels::page>

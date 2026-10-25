<x-filament-panels::page>
    <div wire:init="mountAutoReconcile"></div>

    @php
        $opsReport = $this->opsServerConnectionReport();
        $rows = $this->serviceCards();
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
            H1 đã kết nối ≠ dịch vụ đã được cấp quyền. Reason lifecycle hiển thị dưới từng dịch vụ.
        </p>

        @if (! empty($opsReport['enrolled']))
            <div class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800">
                <div class="text-xs font-semibold text-gray-800 dark:text-gray-100">Thay Installation Key (không cài lại)</div>
                <p class="mt-0.5 text-[11px] text-gray-400">
                    Xác thực Installation mới với OPS-SERVER trước khi đổi. Website, article, content project và cấu hình addon được giữ nguyên.
                    Không tự Revoke key cũ — Admin xác nhận sau trên OPS-SERVER.
                </p>
                <div class="mt-2 flex flex-wrap items-end gap-2">
                    <div class="min-w-[16rem] flex-1">
                        <label class="mb-0.5 block text-[11px] text-gray-500" for="replacementEnrollmentToken">Enrollment token mới</label>
                        <input
                            id="replacementEnrollmentToken"
                            type="text"
                            wire:model="replacementEnrollmentToken"
                            wire:loading.attr="disabled"
                            wire:target="replaceInstallationKey"
                            class="w-full rounded-md border border-gray-300 bg-white px-2 py-1.5 font-mono text-xs dark:border-gray-600 dark:bg-gray-950"
                            placeholder="tok_…"
                            autocomplete="off"
                        />
                    </div>
                    <button
                        type="button"
                        wire:click="replaceInstallationKey"
                        wire:loading.attr="disabled"
                        wire:target="replaceInstallationKey"
                        class="inline-flex items-center gap-1.5 rounded-md border border-amber-300 bg-amber-50 px-2.5 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100 disabled:opacity-50 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100"
                    >
                        <span wire:loading.remove wire:target="replaceInstallationKey">Thay key</span>
                        <span wire:loading wire:target="replaceInstallationKey" class="inline-flex items-center gap-1">
                            <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                            Đang xác thực…
                        </span>
                    </button>
                </div>
                @if ($replacementError)
                    <div class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $replacementError }}</div>
                @endif
            </div>
        @endif
    </div>

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
                        @if (! empty($row['notice_message']))
                            <div class="mt-0.5 text-xs text-gray-600 dark:text-gray-300">
                                <span class="font-medium text-gray-700 dark:text-gray-200">{{ $row['notice_action'] }}</span>
                                @if (! empty($row['notice_at']))
                                    <span class="text-gray-400"> · {{ $row['notice_at'] }}</span>
                                @endif
                                <span class="text-gray-400"> — </span>{{ $row['notice_message'] }}
                            </div>
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
</x-filament-panels::page>

@php($alerts = $this->alerts())
<div class="fi-wi-widget">
    <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-800">
            <div class="flex items-center gap-2">
                <svg class="h-4 w-4 text-amber-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Cần chú ý</h2>
            </div>
            @if (count($alerts) > 0)
                <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-400">
                    {{ count($alerts) }} cảnh báo
                </span>
            @endif
        </div>

        <div class="p-4">
            @if (count($alerts) === 0)
                <div class="flex items-center gap-2 py-1 text-xs text-emerald-600 dark:text-emerald-400">
                    <svg class="h-4 w-4 shrink-0 text-emerald-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <span>Không có cảnh báo quan trọng</span>
                </div>
            @else
                <div class="space-y-3">
                    @foreach ($alerts as $alert)
                        <div @class([
                            'rounded-lg border p-3 text-xs',
                            'border-red-200 bg-red-50/50 dark:border-red-900/50 dark:bg-red-950/20' => $alert['severity'] === 'critical',
                            'border-amber-200 bg-amber-50/50 dark:border-amber-900/50 dark:bg-amber-950/20' => $alert['severity'] === 'warning',
                        ])>
                            <div class="flex items-start justify-between gap-2">
                                <div class="font-medium text-gray-900 dark:text-gray-100">
                                    {{ $alert['title'] }}
                                </div>
                                <span @class([
                                    'inline-flex shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider',
                                    'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300' => $alert['severity'] === 'critical',
                                    'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300' => $alert['severity'] === 'warning',
                                ])>
                                    {{ $alert['severity'] === 'critical' ? 'Khẩn cấp' : 'Cảnh báo' }}
                                </span>
                            </div>

                            <p class="mt-1 text-gray-600 dark:text-gray-300">
                                {{ $alert['message'] }}
                            </p>

                            <div class="mt-2.5 flex items-center justify-between gap-2 border-t border-gray-200/60 pt-2 dark:border-gray-800/60">
                                <span class="text-[11px] text-gray-400 dark:text-gray-500">
                                    {{ $alert['detected_at_humans'] ?: 'Vừa phát hiện' }}
                                </span>

                                @if (! empty($alert['action_url']) && ! empty($alert['action_label']))
                                    <a
                                        href="{{ $alert['action_url'] }}"
                                        class="inline-flex items-center gap-1 font-semibold text-primary-600 hover:text-primary-500 dark:text-primary-400 text-xs"
                                    >
                                        {{ $alert['action_label'] }}
                                        <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                        </svg>
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>

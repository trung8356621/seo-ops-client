<x-filament-panels::page>
    @php($health = $this->health())
    @php($svc = $this->catalogService())
    @php($apiCredentials = $this->apiCredentials())

    <div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="font-semibold text-gray-900 dark:text-white">{{ $health['name'] }}</div>
        <span @class([
            'inline-flex rounded px-2 py-0.5 text-xs font-medium',
            'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => ($health['status'] ?? '') === 'ready',
            'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => in_array($health['status'] ?? '', ['needs_config', 'suspended'], true),
            'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' => ($health['status'] ?? '') === 'connection_error',
            'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => ($health['status'] ?? '') === 'not_entitled',
        ])>
            {{ $health['status_label'] ?? __('site-service.service_configure_health_not_configured') }}
        </span>
        <span class="text-xs text-gray-500">{{ $health['readiness_label'] ?? '—' }}</span>
        @if ($health['database_configured'] ?? false)
            <span class="text-xs text-gray-500">
                {{ $health['host'] ?? '—' }}:{{ $health['port'] ?? '—' }}
                · {{ $health['database'] ?? '—' }}
                · {{ ($health['password_present'] ?? false) ? __('site-service.service_configure_health_password_saved') : __('site-service.service_configure_health_password_unused') }}
            </span>
        @endif
        @unless ($svc)
            <span class="text-xs text-amber-700 dark:text-amber-300">{{ __('site-service.service_configure_health_service_row_missing') }}</span>
        @endunless
    </div>

    {{ $this->form }}

    <div class="mt-8 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('site-service.api_access_section_title') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('site-service.api_access_section_description') }}</p>
            </div>
        </div>

        @if (filled($this->revealedApiKey))
            <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/40" data-api-key-reveal="1">
                <div class="font-semibold text-amber-900 dark:text-amber-200">{{ __('site-service.api_access_copy_now_title') }}</div>
                <p class="mt-1 text-amber-800 dark:text-amber-300">{{ __('site-service.api_access_copy_now_body', ['name' => $this->revealedApiKeyName ?? '']) }}</p>
                <code class="mt-2 block break-all rounded bg-white px-2 py-2 font-mono text-xs text-gray-900 dark:bg-gray-900 dark:text-gray-100" data-revealed-api-key="1">{{ $this->revealedApiKey }}</code>
                <button
                    type="button"
                    wire:click="dismissRevealedApiKey"
                    class="mt-2 text-xs font-medium text-amber-900 underline dark:text-amber-200"
                >
                    {{ __('site-service.api_access_dismiss_reveal') }}
                </button>
            </div>
        @endif

        @if ($apiCredentials->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('site-service.api_access_empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="py-2 pr-3">{{ __('site-service.api_access_name') }}</th>
                            <th class="py-2 pr-3">{{ __('site-service.api_access_prefix') }}</th>
                            <th class="py-2 pr-3">{{ __('site-service.api_access_scopes') }}</th>
                            <th class="py-2 pr-3">{{ __('site-service.api_access_created_at') }}</th>
                            <th class="py-2 pr-3">{{ __('site-service.api_access_expires') }}</th>
                            <th class="py-2 pr-3">{{ __('site-service.api_access_last_used') }}</th>
                            <th class="py-2 pr-3">{{ __('site-service.api_access_status') }}</th>
                            <th class="py-2">{{ __('site-service.api_access_actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($apiCredentials as $cred)
                            <tr wire:key="api-cred-{{ $cred->id }}">
                                <td class="py-2 pr-3 font-medium">{{ $cred->name }}</td>
                                <td class="py-2 pr-3 font-mono text-xs">{{ $cred->key_prefix }}…</td>
                                <td class="py-2 pr-3">
                                    @php($scopes = $cred->scopeList())
                                    {{ $scopes === [] ? '—' : implode(', ', $scopes) }}
                                </td>
                                <td class="py-2 pr-3">{{ optional($cred->created_at)?->toDateTimeString() ?? '—' }}</td>
                                <td class="py-2 pr-3">{{ optional($cred->expires_at)?->toDateTimeString() ?? '—' }}</td>
                                <td class="py-2 pr-3">{{ optional($cred->last_used_at)?->toDateTimeString() ?? '—' }}</td>
                                <td class="py-2 pr-3">{{ __('site-service.api_access_status_'.$cred->statusLabel()) }}</td>
                                <td class="py-2">
                                    <div class="flex flex-wrap gap-2">
                                        @if ($this->canManageApiKeys() && $cred->revoked_at === null)
                                            <button
                                                type="button"
                                                wire:click="rotateApiCredential({{ $cred->id }})"
                                                wire:confirm="{{ __('site-service.api_access_rotate_confirm') }}"
                                                wire:loading.attr="disabled"
                                                wire:target="rotateApiCredential({{ $cred->id }})"
                                                class="text-xs font-medium text-primary-600 underline disabled:opacity-50"
                                            >
                                                <span wire:loading.remove wire:target="rotateApiCredential({{ $cred->id }})">{{ __('site-service.api_access_rotate') }}</span>
                                                <span wire:loading wire:target="rotateApiCredential({{ $cred->id }})">…</span>
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="revokeApiCredential({{ $cred->id }})"
                                                wire:confirm="{{ __('site-service.api_access_revoke_confirm') }}"
                                                wire:loading.attr="disabled"
                                                wire:target="revokeApiCredential({{ $cred->id }})"
                                                class="text-xs font-medium text-danger-600 underline disabled:opacity-50"
                                            >
                                                <span wire:loading.remove wire:target="revokeApiCredential({{ $cred->id }})">{{ __('site-service.api_access_revoke') }}</span>
                                                <span wire:loading wire:target="revokeApiCredential({{ $cred->id }})">…</span>
                                            </button>
                                        @else
                                            <span class="text-xs text-gray-400">—</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>

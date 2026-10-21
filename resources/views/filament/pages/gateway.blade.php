<x-filament-panels::page>
    @php($control = $this->controlGateStatus())
    @php($credentials = $this->apiCredentials())
    @php($canManage = $this->canManageApiKeys())

    <div class="mb-4 flex flex-wrap gap-2 border-b border-gray-200 pb-3 dark:border-gray-700">
        <button
            type="button"
            wire:click="setTab('api_access')"
            @class([
                'rounded-lg px-3 py-1.5 text-sm font-medium',
                'bg-primary-600 text-white' => $activeTab === 'api_access',
                'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200' => $activeTab !== 'api_access',
            ])
        >
            {{ __('gateway.tab_api_access') }}
        </button>
        <button
            type="button"
            wire:click="setTab('control')"
            @class([
                'rounded-lg px-3 py-1.5 text-sm font-medium',
                'bg-primary-600 text-white' => $activeTab === 'control',
                'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200' => $activeTab !== 'control',
            ])
        >
            {{ __('gateway.tab_control') }}
        </button>
    </div>

    @if ($activeTab === 'control')
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('gateway.control_title') }}</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('gateway.control_description') }}</p>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg border border-gray-100 p-3 dark:border-gray-800">
                    <div class="text-xs text-gray-500">{{ __('gateway.control_h1_label') }}</div>
                    <div class="font-semibold">{{ __('gateway.control_h1_'.$control['h1_local_implementation']) }}</div>
                </div>
                <div class="rounded-lg border border-gray-100 p-3 dark:border-gray-800">
                    <div class="text-xs text-gray-500">{{ __('gateway.control_entitlement_label') }}</div>
                    <div class="font-semibold">
                        {{ $control['entitlement_enforcement'] ? __('gateway.control_entitlement_on') : __('gateway.control_entitlement_off') }}
                    </div>
                </div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="py-2 pr-3">{{ __('gateway.control_service') }}</th>
                            <th class="py-2 pr-3">{{ __('gateway.control_active') }}</th>
                            <th class="py-2">{{ __('gateway.control_key_provisioned') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($control['services'] as $svc)
                            <tr>
                                <td class="py-2 pr-3 font-medium">{{ $svc['name'] }} ({{ $svc['slug'] }})</td>
                                <td class="py-2 pr-3">{{ $svc['active'] ? __('gateway.yes') : __('gateway.no') }}</td>
                                <td class="py-2">{{ $svc['key_provisioned'] ? __('gateway.yes') : __('gateway.no') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="py-3 text-gray-500">{{ __('gateway.control_no_services') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('gateway.control_no_secrets') }}</p>
        </div>
    @else
        <div class="mb-4 grid gap-3 sm:grid-cols-2">
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs text-gray-500">{{ __('gateway.summary_active') }}</div>
                <div class="text-2xl font-semibold">{{ $this->activeKeyCount() }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-xs text-gray-500">{{ __('gateway.summary_total') }}</div>
                <div class="text-2xl font-semibold">{{ $this->totalKeyCount() }}</div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('gateway.api_access_title') }}</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('gateway.api_access_description') }}</p>

            @unless ($canManage)
                <p class="mt-3 text-sm text-amber-700 dark:text-amber-300">{{ __('gateway.api_access_root_only') }}</p>
            @endunless

            @if (filled($this->revealedApiKey))
                <div class="mb-4 mt-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/40" data-api-key-reveal="1">
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

            @if ($credentials->isEmpty())
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('gateway.api_access_empty') }}</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                                <th class="py-2 pr-3">{{ __('site-service.api_access_name') }}</th>
                                <th class="py-2 pr-3">{{ __('gateway.api_access_service') }}</th>
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
                            @foreach ($credentials as $cred)
                                @php($svc = $cred->service)
                                <tr wire:key="gw-api-cred-{{ $cred->id }}">
                                    <td class="py-2 pr-3 font-medium">{{ $cred->name }}</td>
                                    <td class="py-2 pr-3">
                                        {{ $svc ? \App\Services\ServiceIdentity::displayName(\App\Services\ServiceIdentity::publicSlugForCatalog((string) $svc->slug)) : '—' }}
                                    </td>
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
                                        @if ($canManage && $cred->revoked_at === null)
                                            <div class="flex flex-wrap gap-2">
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
                                            </div>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>

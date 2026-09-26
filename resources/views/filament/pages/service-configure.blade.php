<x-filament-panels::page>
    @php($health = $this->health())
    @php($svc = $this->catalogService())

    <div class="mb-6 grid gap-3 rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-900 sm:grid-cols-2">
        <div>
            <div class="text-xs text-gray-500">{{ __('site-service.service_configure_health_service_label') }}</div>
            <div class="font-semibold">{{ $health['name'] }} ({{ $health['slug'] }})</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">Status</div>
            <div class="font-semibold">{{ $health['active'] ? 'Active' : 'Inactive' }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">Service key</div>
            <div class="font-semibold">{{ $health['key_provisioned'] ? __('site-service.service_configure_health_key_provisioned') : __('site-service.service_configure_health_key_not_provisioned') }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">Logical DB</div>
            <div class="font-semibold">{{ $health['db_connection'] }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">{{ __('site-service.service_configure_health_db_source_label') }}</div>
            <div class="font-semibold">{{ $health['connection_source'] ?? 'unavailable' }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">Database readiness</div>
            <div class="font-semibold">{{ $health['readiness_label'] ?? __('site-service.service_configure_health_not_configured') }}</div>
        </div>
        @if ($health['database_configured'] ?? false)
            <div>
                <div class="text-xs text-gray-500">Host / Port</div>
                <div class="font-semibold">{{ $health['host'] ?? '—' }}:{{ $health['port'] ?? '—' }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500">Database / User</div>
                <div class="font-semibold">{{ $health['database'] ?? '—' }} / {{ $health['username'] ?? '—' }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500">Password</div>
                <div class="font-semibold">{{ ($health['password_present'] ?? false) ? __('site-service.service_configure_health_password_saved') : __('site-service.service_configure_health_password_unused') }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500">Last tested</div>
                <div class="font-semibold">{{ $health['last_tested_at'] ?? '—' }}</div>
            </div>
        @endif
        @unless ($svc)
            <p class="sm:col-span-2 text-amber-700 dark:text-amber-300">{{ __('site-service.service_configure_health_service_row_missing') }}</p>
        @endunless
    </div>

    {{ $this->form }}
</x-filament-panels::page>

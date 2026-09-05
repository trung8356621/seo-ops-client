<x-filament-panels::page>
    @php
        $installation = $this->getInstallationViewData();
    @endphp

    @if ($installation['is_locked'])
        <div class="mb-4 rounded-xl border border-danger-300 bg-danger-50 p-4 text-danger-800 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-200">
            <div class="text-sm font-semibold">{{ __('client_control.locked_title') }}</div>
            <p class="mt-1 text-sm">{{ __('client_control.locked_message') }}</p>
        </div>
    @endif

    @if ($installation['is_revoked'])
        <div class="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
            <div class="text-sm font-semibold">{{ __('client_control.revoked_title') }}</div>
            <p class="mt-1 text-sm">{{ __('client_control.revoked_message') }}</p>
        </div>
    @endif

    @if ($installation['can_enroll'] && ! $installation['is_connected'])
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('client_control.unregistered_heading') }}</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('client_control.unregistered_body') }}</p>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                <li>{{ __('client_control.benefit_services') }}</li>
                <li>{{ __('client_control.benefit_keys') }}</li>
                <li>{{ __('client_control.benefit_commands') }}</li>
            </ul>

            <form wire:submit="connect" class="mt-6 space-y-5">
                {{ $this->form }}
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="connect">
                    <span wire:loading.remove wire:target="connect">{{ __('client_control.connect') }}</span>
                    <span wire:loading wire:target="connect" class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        {{ __('client_control.connecting') }}
                    </span>
                </x-filament::button>
            </form>
        </div>
    @endif

    @if ($installation['show_status_panel'])
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('client_control.status_heading') }}</h2>
                <span @class([
                    'inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium',
                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $installation['status'] === 'active',
                    'bg-danger-100 text-danger-800 dark:bg-danger-900/40 dark:text-danger-200' => $installation['status'] === 'locked',
                    'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100' => $installation['status'] === 'revoked',
                    'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200' => $installation['status'] === 'unregistered',
                ])>
                    {{ $installation['status_label'] }}
                </span>
                <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                    {{ __('client_control.lock_state') }}: {{ $installation['control_lock_label'] }}
                </span>
            </div>

            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-gray-500">{{ __('client_control.control_server_url') }}</dt>
                    <dd class="text-sm font-medium break-all">{{ $installation['control_server_url'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">{{ __('client_control.installation_id') }}</dt>
                    <dd class="text-sm font-medium break-all">{{ $installation['installation_id'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">{{ __('client_control.client_version') }}</dt>
                    <dd class="text-sm font-medium">{{ $installation['client_version'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">{{ __('client_control.services_revision') }}</dt>
                    <dd class="text-sm font-medium">{{ $installation['services_revision'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">{{ __('client_control.last_command') }}</dt>
                    <dd class="text-sm font-medium break-all">{{ $installation['last_command_id'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">{{ __('client_control.last_command_at') }}</dt>
                    <dd class="text-sm font-medium">{{ $installation['last_command_at'] ?: '—' }}</dd>
                </div>
                @if ($installation['is_locked'] && $installation['locked_at'])
                    <div>
                        <dt class="text-xs text-gray-500">{{ __('client_control.locked_at') }}</dt>
                        <dd class="text-sm font-medium">{{ $installation['locked_at'] }}</dd>
                    </div>
                @endif
            </dl>
        </div>
    @endif

    @if ($installation['can_enroll'] && $installation['is_revoked'])
        <div class="mt-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-base font-semibold">{{ __('client_control.reconnect_heading') }}</h2>
            <form wire:submit="connect" class="mt-4 space-y-5">
                {{ $this->form }}
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="connect">
                    {{ __('client_control.connect') }}
                </x-filament::button>
            </form>
        </div>
    @endif
</x-filament-panels::page>

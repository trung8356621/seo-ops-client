<x-filament-panels::page>
    @php
        $installation = $this->getInstallationViewData();
    @endphp

    @if($installation['is_locked'])
        <div class="rounded-xl border border-danger-300 bg-danger-50 p-4 text-danger-700 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-200">
            {{ __('client_control.locked_message') }}
        </div>
    @endif

    <x-filament::section>
        <x-slot name="heading">{{ __('client_control.status_heading') }}</x-slot>
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-gray-500">{{ __('client_control.status') }}</dt>
                <dd class="text-sm font-medium">{{ $installation['status'] }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500">{{ __('client_control.lock_state') }}</dt>
                <dd class="text-sm font-medium">
                    {{ $installation['is_locked'] ? __('client_control.lock_locked') : __('client_control.lock_active') }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500">{{ __('client_control.control_server_url') }}</dt>
                <dd class="text-sm font-medium break-all">{{ $installation['control_server_url'] ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500">{{ __('client_control.installation_id') }}</dt>
                <dd class="text-sm font-medium break-all">{{ $installation['installation_id'] ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500">{{ __('client_control.client_version') }}</dt>
                <dd class="text-sm font-medium">{{ $installation['client_version'] ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500">{{ __('client_control.last_command') }}</dt>
                <dd class="text-sm font-medium break-all">{{ $installation['last_command_id'] ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500">{{ __('client_control.last_command_at') }}</dt>
                <dd class="text-sm font-medium">{{ $installation['last_command_at'] ?: '—' }}</dd>
            </div>
        </dl>
    </x-filament::section>

    @if($installation['can_enroll'])
        <x-filament::section>
            <x-slot name="heading">{{ __('client_control.connect_heading') }}</x-slot>
            <form wire:submit="connect" class="space-y-6">
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
        </x-filament::section>
    @endif
</x-filament-panels::page>

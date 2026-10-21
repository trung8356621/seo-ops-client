<x-filament-panels::page>
    <div class="rounded-xl border border-gray-200 bg-white p-6 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <p class="text-gray-600 dark:text-gray-300">
            Gateway UI has moved to Services.
        </p>
        <div class="mt-4">
            <x-filament::button tag="a" href="{{ \App\Filament\Pages\ServiceStatusOverview::getUrl() }}" color="primary">
                Open Services
            </x-filament::button>
        </div>
    </div>
</x-filament-panels::page>

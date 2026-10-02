<x-filament-panels::page>
    <x-filament::section>
        <dl class="grid gap-4 md:grid-cols-2">
            <div><dt class="font-medium">Name</dt><dd>{{ $record->name }}</dd></div>
            <div><dt class="font-medium">Key</dt><dd>{{ $record->key }}</dd></div>
            <div><dt class="font-medium">Schema</dt><dd>{{ $record->schema_version }}</dd></div>
            <div><dt class="font-medium">Status</dt><dd>{{ $record->is_active ? 'Active' : 'Inactive' }}</dd></div>
            <div><dt class="font-medium">Created</dt><dd>{{ $record->created_at }}</dd></div>
            <div><dt class="font-medium">Hạn sử dụng</dt><dd>{{ $record->expires_at?->format('d/m/Y H:i') ?? 'Không hết hạn' }} <x-filament::badge :color="\App\IndustryContext\IndustryContextExpiry::status($record->expires_at) === 'expired' ? 'danger' : (\App\IndustryContext\IndustryContextExpiry::status($record->expires_at) === 'expiring' ? 'warning' : 'success')">{{ \App\IndustryContext\IndustryContextExpiry::label($record->expires_at) }}</x-filament::badge></dd></div>
        </dl>
        <pre class="mt-4 max-h-[32rem] overflow-auto rounded-lg bg-gray-950 p-4 text-xs text-gray-100">{{ json_encode($record->context_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </x-filament::section>

    <x-filament::section heading="History (latest 3)">
        <div class="space-y-4">
            @foreach ($this->revisions() as $revision)
                <div class="rounded-lg border p-4">
                    <div class="flex items-center justify-between gap-4">
                        <div>{{ $revision->created_at }} · {{ $revision->expires_at?->format('d/m/Y H:i') ?? 'Không hết hạn' }} @if($revision->is_active)<x-filament::badge color="success">Active</x-filament::badge>@endif <x-filament::badge :color="\App\IndustryContext\IndustryContextExpiry::status($revision->expires_at) === 'expired' ? 'danger' : (\App\IndustryContext\IndustryContextExpiry::status($revision->expires_at) === 'expiring' ? 'warning' : 'success')">{{ \App\IndustryContext\IndustryContextExpiry::label($revision->expires_at) }}</x-filament::badge></div>
                        @if(! $revision->is_active)
                            <x-filament::button wire:click="activateRevision({{ $revision->id }})" size="sm">Dùng bản này</x-filament::button>
                        @endif
                    </div>
                    <details class="mt-3"><summary class="cursor-pointer">Inspect JSON</summary><pre class="mt-2 max-h-96 overflow-auto rounded bg-gray-950 p-3 text-xs text-gray-100">{{ json_encode($revision->context_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>

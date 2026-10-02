<x-filament-panels::page>
    @php
        $core = $this->workspaceCore();
        $branch = $this->branch();
        $identity = (array) ($core->context_json['identity'] ?? []);
        $tabs = [
            'core' => 'Core',
            'discovery' => 'Discovery & Attention',
            'breakout' => 'Breakout',
        ];
    @endphp

    <x-filament::section>
        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">{{ $core->name }}</h2>
        <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-600 dark:text-gray-300">
            <span><strong>Key:</strong> {{ $core->key }}</span>
            <span><strong>Language:</strong> {{ $identity['language'] ?? '—' }}</span>
            <span><strong>Market:</strong> {{ implode(', ', (array) ($identity['market'] ?? [])) ?: '—' }}</span>
        </div>
    </x-filament::section>

    <div class="flex flex-wrap gap-2 border-b border-gray-200 pb-3 dark:border-gray-700">
        @foreach ($tabs as $type => $label)
            <x-filament::button wire:click="selectType('{{ $type }}')" :color="$selectedType === $type ? 'primary' : 'gray'" size="sm">
                {{ $label }}
            </x-filament::button>
        @endforeach
    </div>

    @if ($branch)
        <x-filament::section :heading="$tabs[$selectedType]">
            <div class="mb-4 flex flex-wrap items-center gap-3 text-sm">
                <x-filament::badge :color="$branch->is_active ? 'success' : 'gray'">{{ $branch->is_active ? 'Active' : 'Inactive' }}</x-filament::badge>
                <x-filament::badge :color="\App\IndustryContext\IndustryContextExpiry::status($branch->expires_at) === 'expired' ? 'danger' : (\App\IndustryContext\IndustryContextExpiry::status($branch->expires_at) === 'expiring' ? 'warning' : 'success')">
                    {{ \App\IndustryContext\IndustryContextExpiry::label($branch->expires_at) }}
                </x-filament::badge>
                <span class="text-gray-600 dark:text-gray-300">{{ $branch->updated_at?->format('Y-m-d H:i') ?? $branch->created_at?->format('Y-m-d H:i') }}</span>
                @if ($selectedType !== 'core')
                    @if ($this->isStale($branch))
                        <span class="font-medium text-warning-600">⚠ Core đã thay đổi · Nên tạo lại context này.</span>
                    @else
                        <span class="font-medium text-success-600">✓ Khớp Core hiện tại</span>
                    @endif
                @endif
            </div>

            <x-filament-panels::form id="form" wire:key="industry-context-{{ $selectedType }}-{{ $branch->id }}" wire:submit="save">
                {{ $this->form }}
                <x-filament-panels::form.actions :actions="$this->getCachedFormActions()" :full-width="$this->hasFullWidthFormActions()" />
            </x-filament-panels::form>
        </x-filament::section>
    @else
        <x-filament::section :heading="$tabs[$selectedType]">
            <div class="py-8 text-center">
                <p class="font-medium text-gray-950 dark:text-white">Chưa có {{ $tabs[$selectedType] }} context.</p>
                <p class="mx-auto mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-300">
                    @if ($selectedType === 'discovery')
                        Được tạo từ Core hiện tại và dùng khi Agent cần mở rộng chủ đề/cơ hội nội dung.
                    @else
                        Dùng cho các hướng nội dung xa Core hơn, ưu tiên attention/view nhưng vẫn có liên hệ hợp lý.
                    @endif
                </p>
                <p class="mt-4 text-sm text-gray-500">Dùng Tải Prompt hoặc {{ $selectedType === 'discovery' ? 'Gen Discovery' : 'Gen Breakout' }} ở thanh hành động phía trên.</p>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="History (latest 3)">
        <div class="space-y-3">
            @forelse ($this->revisions() as $revision)
                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span>{{ $revision->created_at?->format('Y-m-d H:i') }}</span>
                            <x-filament::badge :color="$revision->is_active ? 'success' : 'gray'">{{ $revision->is_active ? 'Active' : 'Inactive' }}</x-filament::badge>
                            <x-filament::badge :color="\App\IndustryContext\IndustryContextExpiry::status($revision->expires_at) === 'expired' ? 'danger' : (\App\IndustryContext\IndustryContextExpiry::status($revision->expires_at) === 'expiring' ? 'warning' : 'success')">{{ \App\IndustryContext\IndustryContextExpiry::label($revision->expires_at) }}</x-filament::badge>
                            @if ($selectedType !== 'core' && $this->isStale($revision))
                                <span class="text-warning-600">Core changed / stale</span>
                            @endif
                        </div>
                        @if (! $revision->is_active)
                            <x-filament::button wire:click="activateRevision({{ $revision->id }})" size="sm">Dùng bản này</x-filament::button>
                        @endif
                    </div>
                    <details class="mt-3">
                        <summary class="cursor-pointer text-sm font-medium">Inspect JSON</summary>
                        <div class="mt-2 rounded-lg bg-gray-950">
                            <div class="flex justify-end border-b border-gray-700 px-3 py-2">
                                <button type="button" x-data x-on:click="navigator.clipboard.writeText($refs.json.textContent)" class="text-xs font-semibold text-gray-200">Copy JSON</button>
                            </div>
                            <pre x-ref="json" class="max-h-96 overflow-auto whitespace-pre p-4 font-mono text-xs leading-5 text-gray-100">{{ json_encode($revision->context_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    </details>
                </div>
            @empty
                <p class="text-sm text-gray-500">Chưa có revision.</p>
            @endforelse
        </div>
    </x-filament::section>

    <x-filament-panels::page.unsaved-data-changes-alert />
</x-filament-panels::page>

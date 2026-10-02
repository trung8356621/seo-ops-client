<x-filament-panels::page>
    @php
        $core = $this->workspaceCore();
        $branch = $this->branch();
        $identity = (array) ($core->context_json['identity'] ?? []);
        $tabs = [
            'core' => 'Core',
            'discovery' => 'Knowledge & Search',
            'breakout' => 'Lifestyle & Usage',
            'match' => 'Match & Research',
        ];
        $revisions = $this->revisions();
        $totalRevisions = $revisions->count();
        $currentIndex = $branch ? $revisions->search(fn ($rev) => $rev->id === $branch->id) : false;
        if ($currentIndex === false) {
            $currentIndex = 0;
        }
        $prevRev = $currentIndex > 0 ? $revisions->get($currentIndex - 1) : null;
        $nextRev = $currentIndex < ($totalRevisions - 1) ? $revisions->get($currentIndex + 1) : null;
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
            <x-filament::button tag="a" :href="$this->tabUrl($type)" :color="$selectedType === $type ? 'primary' : 'gray'" size="sm">
                {{ $label }}
            </x-filament::button>
        @endforeach
    </div>

    <x-filament::section :heading="$tabs[$selectedType]">
        @if ($totalRevisions > 1)
            <x-slot name="headerEnd">
                <div class="flex items-center gap-2 text-sm text-gray-500">
                    @if ($prevRev)
                        <a href="{{ $this->revisionUrl($selectedType, $prevRev->id) }}" class="p-1 font-bold text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white" title="Revision mới hơn">‹</a>
                    @else
                        <span class="p-1 text-gray-300 dark:text-gray-600 cursor-not-allowed">‹</span>
                    @endif
                    <span class="font-mono text-xs">{{ $currentIndex + 1 }} / {{ $totalRevisions }}</span>
                    @if ($nextRev)
                        <a href="{{ $this->revisionUrl($selectedType, $nextRev->id) }}" class="p-1 font-bold text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white" title="Revision cũ hơn">›</a>
                    @else
                        <span class="p-1 text-gray-300 dark:text-gray-600 cursor-not-allowed">›</span>
                    @endif
                </div>
            </x-slot>
        @endif

        <div class="mb-4 flex flex-wrap justify-end gap-2">
            <x-filament::button tag="a" :href="route('admin.industry-context.prompt.download', ['key' => $core->key, 'type' => $selectedType])" target="_blank" color="gray" icon="heroicon-o-arrow-down-tray" size="sm">
                Tải Prompt tạo JSON
            </x-filament::button>
            @if ($branch)
                <x-filament::button tag="a" :href="route('admin.industry-context.json.download', ['profile' => $branch])" target="_blank" icon="heroicon-o-arrow-down-tray" size="sm">
                    Tải JSON
                </x-filament::button>
            @else
                <x-filament::button disabled color="gray" icon="heroicon-o-arrow-down-tray" size="sm" title="Tab này chưa có JSON để tải">
                    Tải JSON
                </x-filament::button>
            @endif
        </div>

        @if ($branch)
            <div class="mb-4 flex flex-wrap items-center gap-3 text-sm">
                <x-filament::badge :color="$branch->is_active ? 'success' : 'gray'">{{ $branch->is_active ? 'Active' : 'Inactive' }}</x-filament::badge>
                @if (! $branch->is_active)
                    <x-filament::button wire:click="activateRevision({{ $branch->id }})" size="xs" color="gray">
                        Dùng bản này
                    </x-filament::button>
                @endif
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
        @else
            <div class="mb-4 rounded-lg bg-gray-50 p-4 text-sm dark:bg-gray-800/50">
                <p class="font-medium text-gray-950 dark:text-white">Chưa có {{ $tabs[$selectedType] }} context.</p>
                <p class="mt-1 text-gray-600 dark:text-gray-300">
                    @if ($selectedType === 'discovery')
                        Được tạo từ Core hiện tại và dùng khi Agent cần mở rộng chủ đề/cơ hội nội dung.
                    @elseif ($selectedType === 'match')
                        Từ vựng ontology ổn định của ngành dành cho đối sánh deterministic; không chứa dữ liệu website.
                    @else
                        Dùng cho các hướng nội dung xa Core hơn, ưu tiên attention/view nhưng vẫn có liên hệ hợp lý.
                    @endif
                </p>
                <p class="mt-4 text-sm text-gray-500">Bạn có thể tải prompt để tạo JSON từ Core. Nút Tải JSON sẽ khả dụng sau khi tab có context.</p>
            </div>
        @endif

        <x-filament-panels::form id="form" wire:key="industry-context-form-{{ $selectedType }}-{{ $branch?->id ?? 'empty' }}" wire:submit="saveManualRevision">
            {{ $this->form }}
            <x-filament-panels::form.actions :actions="$this->getCachedFormActions()" :full-width="$this->hasFullWidthFormActions()" />
        </x-filament-panels::form>
    </x-filament::section>

    <x-filament-panels::page.unsaved-data-changes-alert />
</x-filament-panels::page>

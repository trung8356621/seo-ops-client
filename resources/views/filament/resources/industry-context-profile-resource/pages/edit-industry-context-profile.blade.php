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
            <x-filament::button type="button" wire:click="selectType('{{ $type }}')" wire:target="selectType" wire:loading.attr="disabled" :color="$selectedType === $type ? 'primary' : 'gray'" size="sm">
                {{ $label }}
            </x-filament::button>
        @endforeach
    </div>

    <x-filament::section :heading="$tabs[$selectedType]">
        @if ($totalRevisions > 1)
            <x-slot name="headerEnd">
                <div class="flex items-center gap-2 text-sm text-gray-500">
                    @if ($prevRev)
                        <button type="button" wire:click="selectRevision({{ $prevRev->id }})" wire:target="selectRevision" wire:loading.attr="disabled" class="p-1 font-bold text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white" title="Revision mới hơn">‹</button>
                    @else
                        <span class="p-1 text-gray-300 dark:text-gray-600 cursor-not-allowed">‹</span>
                    @endif
                    <span class="font-mono text-xs">{{ $currentIndex + 1 }} / {{ $totalRevisions }}</span>
                    @if ($nextRev)
                        <button type="button" wire:click="selectRevision({{ $nextRev->id }})" wire:target="selectRevision" wire:loading.attr="disabled" class="p-1 font-bold text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white" title="Revision cũ hơn">›</button>
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

        @if ($selectedType === 'match')
            @php
                $activeMatch = $this->activeMatchRevision();
                $matchView = $this->matchResearchView();
                $groupLabels = [];
                foreach (\Omnichannel\Addons\SearchFoundation\Enums\IndustryGroupType::cases() as $groupType) {
                    $groupLabels[$groupType->value] = $groupType->label();
                }
                $secondaryLabels = [
                    'generic_cores' => 'Generic cores',
                    'service_intent_terms' => 'Service intent terms',
                    'aliases' => 'Aliases',
                    'ambiguities' => 'Ambiguities',
                ];
                $viewingPreview = $branch !== null && ($activeMatch === null || (int) $activeMatch->id !== (int) $branch->id);
            @endphp
            <div class="mb-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
                <div class="flex flex-wrap gap-x-6 gap-y-1">
                    <span><strong>Industry Context key:</strong> {{ $core->key }}</span>
                    <span><strong>Selected revision:</strong> {{ $branch?->id ?? '—' }}</span>
                    <span><strong>Active revision:</strong> {{ $activeMatch?->id ?? '—' }}</span>
                </div>
                @if ($branch)
                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::badge :color="$branch->is_active ? 'success' : 'gray'">{{ $branch->is_active ? 'Active' : 'Inactive' }}</x-filament::badge>
                        @if (! $branch->is_active)
                            <x-filament::button wire:click="activateRevision({{ $branch->id }})" wire:target="activateRevision" wire:loading.attr="disabled" size="xs" color="gray">
                                Dùng bản này
                            </x-filament::button>
                        @endif
                        @if ($this->isStale($branch))
                            <span class="font-medium text-warning-600">Stale · Core đã thay đổi · Nên tạo lại context này.</span>
                        @else
                            <span class="font-medium text-success-600">Fresh · Khớp Core hiện tại</span>
                        @endif
                        <span>Created {{ $branch->created_at?->format('Y-m-d H:i') ?? '—' }}</span>
                        <span>Updated {{ $branch->updated_at?->format('Y-m-d H:i') ?? '—' }}</span>
                    </div>
                @endif
                @if ($activeMatch === null)
                    <p class="font-medium text-warning-600">Không có Match revision đang active. Runtime không dùng Industry Groups từ màn này.</p>
                @endif
                @if ($viewingPreview)
                    <p class="rounded-lg bg-gray-50 p-3 font-medium text-gray-950 dark:bg-gray-800/50 dark:text-white">Đây là bản xem trước của revision {{ $branch->id }}, không phải dữ liệu runtime.</p>
                @endif
            </div>

            <div class="mb-6 space-y-4">
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">Industry Groups</h3>
                <p class="text-sm text-gray-500">Projection read-only của taxonomy trên revision đang xem. Không phải kết quả Python live.</p>
                @forelse ($groupLabels as $groupKey => $groupLabel)
                    @if (! empty($matchView['groups'][$groupKey]))
                        <div>
                            <h4 class="mb-2 text-sm font-medium text-gray-950 dark:text-white">{{ $groupLabel }}</h4>
                            <ul class="space-y-2">
                                @foreach ($matchView['groups'][$groupKey] as $row)
                                    <li class="rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                                        <div class="font-medium text-gray-950 dark:text-white">{{ $row['canonical'] }}</div>
                                        <div class="mt-1 text-gray-600 dark:text-gray-300">Aliases: {{ $row['aliases'] === [] ? '—' : implode(', ', $row['aliases']) }}</div>
                                        <div class="mt-1 flex flex-wrap gap-2">
                                            <x-filament::badge color="gray">{{ $row['match_mode'] ?? 'phrase' }}</x-filament::badge>
                                            <x-filament::badge color="gray">{{ $groupLabel }}</x-filament::badge>
                                            @if ($row['enabled'] !== null)
                                                <x-filament::badge :color="$row['enabled'] ? 'success' : 'gray'">{{ $row['enabled'] ? 'Enabled' : 'Disabled' }}</x-filament::badge>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @empty
                    <p class="text-sm text-gray-500">Revision này chưa có Industry Group.</p>
                @endforelse
                @if (collect($matchView['groups'])->filter()->isEmpty())
                    <p class="text-sm text-gray-500">Revision này chưa có Industry Group.</p>
                @endif
            </div>

            <div class="mb-6 space-y-4">
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">Other Industry Rules</h3>
                @forelse ($secondaryLabels as $groupKey => $groupLabel)
                    @if (! empty($matchView['secondary'][$groupKey]))
                        <div>
                            <h4 class="mb-2 text-sm font-medium text-gray-950 dark:text-white">{{ $groupLabel }}</h4>
                            <ul class="space-y-2">
                                @foreach ($matchView['secondary'][$groupKey] as $row)
                                    <li class="rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                                        <div class="font-medium text-gray-950 dark:text-white">{{ $row['canonical'] }}</div>
                                        @if ($row['aliases'] !== [])
                                            <div class="mt-1 text-gray-600 dark:text-gray-300">Aliases: {{ implode(', ', $row['aliases']) }}</div>
                                        @endif
                                        @if ($row['do_not_confuse_with'] !== [])
                                            <div class="mt-1 text-gray-600 dark:text-gray-300">Do not confuse with: {{ implode(', ', $row['do_not_confuse_with']) }}</div>
                                        @endif
                                        <x-filament::badge color="gray">{{ $row['match_mode'] ?? 'phrase' }}</x-filament::badge>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @empty
                @endforelse
                @if (collect($matchView['secondary'])->filter()->isEmpty())
                    <p class="text-sm text-gray-500">Không có secondary rule trên revision này.</p>
                @endif
            </div>

            <div class="mb-6 rounded-lg border border-gray-200 p-4 text-sm dark:border-gray-700">
                <h3 class="font-semibold text-gray-950 dark:text-white">Live Matching</h3>
                <p class="mt-1 text-gray-600 dark:text-gray-300">Live Industry Match chạy theo site, chỉ dùng Match revision đang active, và gọi semantic service. Không có matcher PHP dự phòng. Trang này không chọn site và không chạy match trên revision đang xem. Alias và canonical trong JSON là evidence thủ công.</p>
                <x-filament::button tag="a" :href="$this->liveIndustryMatchUrl()" color="gray" size="sm" class="mt-3">
                    Mở Live Industry Match
                </x-filament::button>
            </div>
        @elseif ($branch)
            <div class="mb-4 flex flex-wrap items-center gap-3 text-sm">
                <x-filament::badge :color="$branch->is_active ? 'success' : 'gray'">{{ $branch->is_active ? 'Active' : 'Inactive' }}</x-filament::badge>
                @if (! $branch->is_active)
                    <x-filament::button wire:click="activateRevision({{ $branch->id }})" wire:target="activateRevision" wire:loading.attr="disabled" size="xs" color="gray">
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
        @elseif ($selectedType !== 'match')
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

        @if ($selectedType === 'match')
            <details class="rounded-lg border border-gray-200 dark:border-gray-700">
                <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-gray-950 dark:text-white">Advanced — Source JSON</summary>
                <div class="px-4 pb-4">
                    <x-filament-panels::form id="form" wire:key="industry-context-form-{{ $selectedType }}-{{ $branch?->id ?? 'empty' }}" wire:submit="saveManualRevision">
                        {{ $this->form }}
                        <x-filament-panels::form.actions :actions="$this->getCachedFormActions()" :full-width="$this->hasFullWidthFormActions()" />
                    </x-filament-panels::form>
                </div>
            </details>
        @else
            <x-filament-panels::form id="form" wire:key="industry-context-form-{{ $selectedType }}-{{ $branch?->id ?? 'empty' }}" wire:submit="saveManualRevision">
                {{ $this->form }}
                <x-filament-panels::form.actions :actions="$this->getCachedFormActions()" :full-width="$this->hasFullWidthFormActions()" />
            </x-filament-panels::form>
        @endif
    </x-filament::section>

    <x-filament-panels::page.unsaved-data-changes-alert />
</x-filament-panels::page>

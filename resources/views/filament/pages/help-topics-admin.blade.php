<x-filament-panels::page>
    @php
        $summary = $this->coverageSummary;
        $groups = $this->groupedRows;
        $groupOptions = $this->groupOptions();
    @endphp

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm text-gray-500">
            Version {{ $summary['version'] ?? '—' }}
            · Covered {{ $summary['covered'] }}
            · Missing {{ $summary['missing'] }}
            · Unused {{ $summary['unused'] }}
        </div>
        <div class="flex flex-wrap gap-2">
            <x-filament::button color="gray" wire:click="syncRemote" wire:loading.attr="disabled" wire:target="syncRemote">
                <span wire:loading.remove wire:target="syncRemote">Sync Help</span>
                <span wire:loading wire:target="syncRemote" class="inline-flex items-center gap-2">
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    Syncing…
                </span>
            </x-filament::button>
            <x-filament::button color="warning" tag="a" :href="$this->createUrl()">
                New Topic
            </x-filament::button>
        </div>
    </div>

    <div class="mb-4 rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
        <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Search title, key, summary…"
                class="fi-input block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
            />
            <x-select wire:model.live="filterGroup" class="text-sm">
                <option value="">All groups</option>
                @foreach ($groupOptions as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </x-select>
            <x-select wire:model.live="filterCoverage" class="text-sm">
                <option value="">All coverage</option>
                <option value="covered">Covered</option>
                <option value="missing">Missing</option>
                <option value="unused">Unused</option>
            </x-select>
        </div>
    </div>

    <div class="space-y-4">
        @foreach ($groups as $group)
            <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900 overflow-hidden" x-data="{ open: true }">
                <div class="flex items-center gap-2 px-4 py-3 bg-gray-50 dark:bg-gray-950 border-b border-gray-200 dark:border-gray-800">
                    <button
                        type="button"
                        class="flex-1 flex items-center justify-between text-left min-w-0"
                        x-on:click="open = !open"
                    >
                        <span class="font-medium text-sm truncate">
                            {{ $group['group_title'] }}
                            <span class="text-gray-500 font-normal">({{ count($group['rows']) }})</span>
                        </span>
                        <span class="text-xs text-gray-400 ml-2" x-text="open ? '▾' : '▸'"></span>
                    </button>
                    <label class="shrink-0 inline-flex items-center gap-1.5 text-xs text-gray-500" onclick="event.stopPropagation()">
                        <span class="hidden sm:inline">Order</span>
                        <input
                            type="number"
                            value="{{ (int) ($group['sort_order'] ?? 0) }}"
                            wire:blur="updateGroupSortOrder('{{ $group['group_id'] }}', $event.target.value)"
                            wire:keydown.enter.prevent="updateGroupSortOrder('{{ $group['group_id'] }}', $event.target.value)"
                            class="fi-input w-[4.5rem] rounded-lg border-gray-300 text-sm tabular-nums dark:border-gray-600 dark:bg-gray-800"
                            title="Group sort_order (Global Help + Admin)"
                        />
                    </label>
                    <a
                        href="{{ $this->createUrl(null, $group['group_id']) }}"
                        class="shrink-0 text-sm font-medium text-amber-700 hover:underline dark:text-amber-400"
                        onclick="event.stopPropagation()"
                    >
                        + Tạo Help
                    </a>
                </div>

                <div x-show="open" x-cloak>
                    @if (count($group['rows']) === 0)
                        <p class="px-4 py-6 text-sm text-gray-500">Chưa có Help topic.</p>
                    @else
                        <table class="w-full text-sm">
                            <thead class="text-xs uppercase text-gray-500 border-b border-gray-100 dark:border-gray-800">
                                <tr>
                                    <th class="px-4 py-2 text-left font-medium">Title</th>
                                    <th class="px-4 py-2 text-left font-medium">Context key</th>
                                    <th class="px-4 py-2 text-left font-medium">Coverage</th>
                                    <th class="px-4 py-2 text-left font-medium">Updated</th>
                                    <th class="px-4 py-2 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($group['rows'] as $row)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/60">
                                        <td class="px-4 py-2.5">
                                            @if ($row['coverage'] === 'missing')
                                                <span class="font-medium">{{ $row['title'] }}</span>
                                            @else
                                                <a href="{{ $this->editUrl($row['key']) }}" class="font-medium text-amber-700 hover:underline dark:text-amber-400">
                                                    {{ $row['title'] }}
                                                </a>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 font-mono text-xs text-gray-600 dark:text-gray-300">{{ $row['key'] }}</td>
                                        <td class="px-4 py-2.5">
                                            <span class="text-[10px] uppercase tracking-wide rounded px-1.5 py-0.5
                                                @if($row['coverage'] === 'covered') bg-green-100 text-green-800
                                                @elseif($row['coverage'] === 'missing') bg-red-100 text-red-800
                                                @else bg-gray-100 text-gray-700
                                                @endif
                                            ">{{ $row['coverage'] }}</span>
                                        </td>
                                        <td class="px-4 py-2.5 text-gray-500">{{ $row['updated_at_display'] }}</td>
                                        <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                            @if ($row['coverage'] === 'missing')
                                                <a href="{{ $this->createUrl($row['key'], $row['group'] ?: null) }}" class="text-amber-700 hover:underline dark:text-amber-400">
                                                    Create Help
                                                </a>
                                            @else
                                                <a href="{{ $this->editUrl($row['key']) }}" class="text-amber-700 hover:underline dark:text-amber-400">
                                                    Edit
                                                </a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>

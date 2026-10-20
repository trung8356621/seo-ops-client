@php
    $selectedPreset = strtolower(trim((string) ($this->data['preset'] ?? 'mid')));
    if (! in_array($selectedPreset, ['low', 'mid', 'high'], true)) {
        $selectedPreset = 'mid';
    }
    $savedPreset = strtolower(trim((string) ($this->savedPreset ?? 'mid')));
    $cards = [
        'low' => [
            'title' => __('performance.preset_low'),
            'subtitle' => __('performance.preset_low_subtitle'),
            'jobs' => 1,
            'background' => __('performance.mode_restricted'),
            'threshold' => 1,
            'cpu' => __('performance.hosting_low_cpu'),
            'ram' => __('performance.hosting_low_ram'),
            'icon' => 'heroicon-o-shield-check',
        ],
        'mid' => [
            'title' => __('performance.preset_mid'),
            'subtitle' => __('performance.preset_mid_subtitle'),
            'jobs' => 3,
            'background' => __('performance.mode_adaptive'),
            'threshold' => 3,
            'cpu' => __('performance.hosting_mid_cpu'),
            'ram' => __('performance.hosting_mid_ram'),
            'icon' => 'heroicon-o-adjustments-horizontal',
        ],
        'high' => [
            'title' => __('performance.preset_high'),
            'subtitle' => __('performance.preset_high_subtitle'),
            'jobs' => 4,
            'background' => __('performance.mode_adaptive'),
            'threshold' => 6,
            'cpu' => __('performance.hosting_high_cpu'),
            'ram' => __('performance.hosting_high_ram'),
            'icon' => 'heroicon-o-rocket-launch',
        ],
    ];

@endphp

<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'performance'])

            <div class="seo-settings-main seo-settings-main--with-global-save">
                <header class="seo-settings-header">
                    <h1>{{ __('performance.page_title') }}</h1>
                    <p>{{ __('performance.page_description') }}</p>
                </header>

                <form wire:submit="save" class="seo-settings-general-form">
                    <div class="sr-only" aria-hidden="true">{{ $this->form }}</div>

                    <section class="mt-3 max-w-5xl">
                        <div>
                            <div class="flex items-start gap-3">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-cyan-400 to-primary-600 text-white shadow-sm shadow-primary-500/20">
                                    <x-filament::icon icon="heroicon-o-bolt" class="h-6 w-6" />
                                </span>
                                <div>
                                    <h2 class="text-lg font-bold tracking-tight text-gray-950 dark:text-white">
                                        {{ __('performance.section_title') }}
                                    </h2>
                                    <p class="mt-0.5 text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                                        {{ __('performance.section_description') }}
                                    </p>
                                </div>
                            </div>

                            <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3" role="radiogroup" aria-label="{{ __('performance.preset_label') }}">
                                @foreach ($cards as $key => $card)
                                    @php
                                        $isSelected = $selectedPreset === $key;
                                        $isSaved = $savedPreset === $key;
                                    @endphp
                                    <button
                                        type="button"
                                        wire:click="selectPreset('{{ $key }}')"
                                        wire:loading.attr="disabled"
                                        role="radio"
                                        aria-checked="{{ $isSelected ? 'true' : 'false' }}"
                                        @class([
                                            'group relative flex h-full min-h-96 flex-col overflow-hidden rounded-2xl p-4 text-left transition duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/70 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900 sm:p-5',
                                            'bg-gradient-to-br from-slate-100 via-cyan-50 to-teal-100 ring-2 ring-cyan-500 dark:from-slate-800 dark:via-cyan-950/60 dark:to-teal-950/60' => $isSelected && $key === 'low',
                                            'bg-gradient-to-br from-amber-100 via-orange-50 to-rose-100 ring-2 ring-orange-500 dark:from-amber-950/70 dark:via-orange-950/50 dark:to-rose-950/60' => $isSelected && $key === 'mid',
                                            'bg-gradient-to-br from-violet-100 via-fuchsia-50 to-rose-100 ring-2 ring-violet-500 dark:from-violet-950/70 dark:via-fuchsia-950/50 dark:to-rose-950/60' => $isSelected && $key === 'high',
                                            'bg-slate-100/80 hover:-translate-y-1 hover:bg-cyan-50 dark:bg-slate-800/70 dark:hover:bg-cyan-950/30' => ! $isSelected && $key === 'low',
                                            'bg-gradient-to-br from-amber-50 to-orange-100/80 hover:-translate-y-1 hover:from-amber-100 hover:to-orange-100 dark:from-amber-950/30 dark:to-orange-950/30' => ! $isSelected && $key === 'mid',
                                            'bg-violet-50/80 hover:-translate-y-1 hover:bg-violet-100 dark:bg-violet-950/25 dark:hover:bg-violet-950/40' => ! $isSelected && $key === 'high',
                                        ])
                                    >
                                        <span @class([
                                            'absolute inset-x-0 top-0 h-1.5',
                                            'bg-gradient-to-r from-cyan-400 to-teal-500' => $key === 'low',
                                            'bg-gradient-to-r from-amber-400 via-orange-500 to-rose-500' => $key === 'mid',
                                            'bg-gradient-to-r from-violet-500 via-fuchsia-500 to-rose-500' => $key === 'high',
                                        ]) aria-hidden="true"></span>
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <span @class([
                                                    'flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl',
                                                    'bg-cyan-200/80 text-cyan-800 dark:bg-cyan-500/20 dark:text-cyan-300' => $key === 'low',
                                                    'bg-gradient-to-br from-amber-300 to-orange-400 text-white' => $key === 'mid',
                                                    'bg-gradient-to-br from-violet-500 to-fuchsia-500 text-white' => $key === 'high',
                                                ])>
                                                    <x-filament::icon :icon="$card['icon']" class="h-7 w-7" />
                                                </span>
                                                <div class="mt-3 flex min-h-7 flex-wrap items-center gap-2">
                                                    <span @class([
                                                        'text-lg font-bold tracking-tight text-gray-950 dark:text-white',
                                                    ])>{{ $card['title'] }}</span>
                                                    @if ($key === 'mid')
                                                        <span class="rounded-full bg-orange-500 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide text-white">
                                                            {{ __('performance.badge_recommended') }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <p class="mt-0.5 min-h-8 text-xs leading-4 text-gray-500 dark:text-gray-400">{{ $card['subtitle'] }}</p>
                                            </div>
                                            @if ($isSelected)
                                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-950 text-white dark:bg-white dark:text-gray-900">
                                                    <x-filament::icon icon="heroicon-m-check" class="h-4 w-4" />
                                                </span>
                                            @endif
                                        </div>

                                        <div class="mt-5 grid grid-cols-2 gap-2 text-xs">
                                            <span class="rounded-xl bg-white/65 p-2.5 dark:bg-white/[0.06]">
                                                <span class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"><x-filament::icon icon="heroicon-o-queue-list" class="h-4 w-4" />{{ __('performance.meta_jobs') }}</span>
                                                <strong class="mt-1 block text-sm text-gray-950 dark:text-white">{{ $card['jobs'] }}</strong>
                                            </span>
                                            <span class="rounded-xl bg-white/65 p-2.5 dark:bg-white/[0.06]">
                                                <span class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"><x-filament::icon icon="heroicon-o-moon" class="h-4 w-4" />{{ __('performance.meta_background') }}</span>
                                                <strong class="mt-1 block text-sm text-gray-950 dark:text-white">{{ $card['background'] }}</strong>
                                            </span>
                                            <span class="col-span-2 rounded-xl bg-white/65 p-2.5 dark:bg-white/[0.06]">
                                                <span class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"><x-filament::icon icon="heroicon-o-users" class="h-4 w-4" />{{ __('performance.meta_threshold') }}</span>
                                                <strong class="mt-1 block text-sm text-gray-950 dark:text-white">{{ $card['threshold'] }}</strong>
                                            </span>
                                        </div>

                                        <p class="mt-3 flex items-start gap-2 text-[11px] leading-relaxed text-gray-600 dark:text-gray-300">
                                            <x-filament::icon icon="heroicon-o-rectangle-group" class="mt-0.5 h-4 w-4 shrink-0" />
                                            {{ __('performance.chip_tabs') }}
                                        </p>

                                        <div class="mt-4 grid grid-cols-2 gap-2 border-t border-gray-900/10 pt-3 dark:border-white/10">
                                            <span class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                                                <x-filament::icon icon="heroicon-o-cpu-chip" class="h-5 w-5 text-gray-500" />
                                                <span><small class="block text-[9px] font-semibold uppercase tracking-wider opacity-60">{{ __('performance.hosting_col_cpu') }}</small><strong class="text-gray-950 dark:text-white">{{ $card['cpu'] }}</strong></span>
                                            </span>
                                            <span class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                                                <x-filament::icon icon="heroicon-o-circle-stack" class="h-5 w-5 text-gray-500" />
                                                <span><small class="block text-[9px] font-semibold uppercase tracking-wider opacity-60">{{ __('performance.hosting_col_ram') }}</small><strong class="text-gray-950 dark:text-white">{{ $card['ram'] }}</strong></span>
                                            </span>
                                        </div>

                                        <span class="mt-auto flex min-h-7 items-end pt-3 text-[10px] font-extrabold uppercase tracking-widest text-gray-900 dark:text-white">
                                            @if ($isSaved)
                                                {{ __('performance.badge_current') }}
                                            @elseif ($isSelected)
                                                {{ __('performance.badge_selected') }}
                                            @endif
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </section>

                    <div class="seo-settings-global-save" data-seo-settings-global-save>
                        <x-filament::button type="submit" icon="heroicon-m-bolt" wire:loading.attr="disabled" wire:target="save" class="shadow-sm">
                            <span wire:loading.remove wire:target="save">{{ __('performance.save') }}</span>
                            <span wire:loading wire:target="save">{{ __('performance.saving') }}</span>
                        </x-filament::button>
                    </div>
                </form>
            </div>
        </div>
    </x-filament-panels::page>
</div>

@php
    use Illuminate\View\ComponentAttributeBag;
@endphp

@props([
    'debounce' => '500ms',
    'onBlur' => false,
    'placeholder' => __('filament-tables::table.fields.search.placeholder'),
    'wireModel' => 'tableSearch',
])

@php
    // Dataset table search: commit on Enter only (ignore debounce/onBlur props).
    $enterPlaceholder = filled($placeholder) && ! in_array((string) $placeholder, ['Search', 'Tìm kiếm', 'Search...'], true)
        ? (string) $placeholder
        : 'Nhập từ khóa rồi nhấn Enter để tìm...';
@endphp

<div
    x-data="{
        draft: '',
        init() {
            this.draft = String($wire.get(@js($wireModel)) ?? '');
            $wire.$watch(@js($wireModel), (value) => {
                this.draft = String(value ?? '');
            });
        },
        commit() {
            $wire.set(@js($wireModel), String(this.draft ?? '').trim());
        },
        clear() {
            this.draft = '';
            $wire.set(@js($wireModel), '');
        },
    }"
    x-id="['input']"
    {{ $attributes->class(['fi-ta-search-field']) }}
>
    <label x-bind:for="$id('input')" class="sr-only">
        {{ __('filament-tables::table.fields.search.label') }}
    </label>

    <x-filament::input.wrapper
        inline-prefix
        prefix-icon="heroicon-m-magnifying-glass"
        prefix-icon-alias="tables::search-field"
        :wire:target="$wireModel"
    >
        <x-filament::input
            :attributes="
                (new ComponentAttributeBag)->merge([
                    'autocomplete' => 'off',
                    'inlinePrefix' => true,
                    'maxlength' => 1000,
                    'placeholder' => $enterPlaceholder,
                    'type' => 'search',
                    'wire:key' => $this->getId() . '.table.' . $wireModel . '.field.input',
                    'x-bind:id' => '$id(\'input\')',
                    'x-model' => 'draft',
                    'x-on:keydown.enter.prevent' => 'commit()',
                ])
            "
        />
    </x-filament::input.wrapper>
</div>

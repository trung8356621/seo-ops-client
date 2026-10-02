@php
    $statePath = $getStatePath();
@endphp

@once
    @vite(['resources/js/admin/code-editor/index.js'], 'build-code-editor')
@endonce

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:key="prompt-markdown-code-editor-{{ $getId() }}"
        wire:ignore
        x-data="{ state: $wire.entangle(@js($statePath)) }"
        data-code-editor
        data-language="markdown"
        data-disabled="{{ $isDisabled() ? 'true' : 'false' }}"
        class="code-editor-shell"
        style="--code-editor-min-height: 280px"
    >
        <div class="code-editor-toolbar">
            <span class="code-editor-toolbar__label">Markdown</span>
            <button type="button" data-code-editor-copy>Copy</button>
        </div>
        <div data-code-editor-surface class="code-editor-surface"></div>
    </div>
</x-dynamic-component>

@php
    $statePath = $getStatePath();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            formatJson() {
                try {
                    const formatted = JSON.stringify(JSON.parse(this.$refs.editor.value), null, 2);
                    this.$refs.editor.value = formatted;
                    this.$refs.editor.dispatchEvent(new Event('input', { bubbles: true }));
                } catch (error) {
                    this.$refs.editor.focus();
                }
            }
        }"
        class="overflow-hidden rounded-xl border border-gray-300 bg-gray-950 shadow-sm ring-primary-600/20 focus-within:border-primary-500 focus-within:ring-2 dark:border-gray-700"
    >
        <div class="flex items-center justify-between border-b border-gray-700 bg-gray-900 px-4 py-2">
            <span class="font-mono text-xs font-semibold uppercase tracking-wide text-gray-300">JSON</span>
            <button type="button" x-on:click="formatJson()" class="rounded-md px-2.5 py-1 text-xs font-medium text-gray-300 hover:bg-gray-800 hover:text-white">
                Format JSON
            </button>
        </div>
        <textarea
            x-ref="editor"
            id="{{ $getId() }}"
            rows="{{ $getRows() ?? 30 }}"
            spellcheck="false"
            data-gramm="false"
            data-gramm_editor="false"
            data-enable-grammarly="false"
            wire:model="{{ $statePath }}"
            class="block min-h-[45rem] max-h-[70vh] w-full resize-y overflow-auto whitespace-pre border-0 bg-gray-950 p-4 font-mono text-sm leading-6 text-gray-100 caret-white outline-none placeholder:text-gray-500 focus:ring-0"
        ></textarea>
    </div>
</x-dynamic-component>

<x-filament-panels::page>
    @php
        $groups = $this->groupOptions();
        $isCreate = $this instanceof \App\Filament\Pages\HelpTopicCreate;
    @endphp

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ $this->listUrl() }}" class="text-sm text-amber-700 hover:underline dark:text-amber-400">
            ← Help Topics
        </a>
        <div class="flex flex-wrap gap-2">
            <x-filament::button color="gray" wire:click="openPreview" wire:loading.attr="disabled" wire:target="openPreview">
                <span wire:loading.remove wire:target="openPreview">Preview</span>
                <span wire:loading wire:target="openPreview">…</span>
            </x-filament::button>
            <x-filament::button color="warning" wire:click="publish" wire:loading.attr="disabled" wire:target="publish">
                Publish
            </x-filament::button>
        </div>
    </div>

    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <div class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Metadata</div>

            <div class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-2">
                <label class="block text-sm min-w-0">
                    <span class="text-gray-600">Title</span>
                    <input type="text" wire:model.live.debounce.300ms="formTitle" class="fi-input mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                </label>

                <label class="block text-sm min-w-0">
                    <span class="text-gray-600">Group</span>
                    <x-select
                        wire:model.live="formGroup"
                        class="mt-1 text-sm"
                        :disabled="! $isCreate"
                    >
                        @foreach ($groups as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </x-select>
                </label>

                <label class="block text-sm min-w-0">
                    <span class="text-gray-600">Summary</span>
                    <textarea wire:model="formSummary" rows="3" class="fi-input mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"></textarea>
                </label>

                <label class="block text-sm min-w-0">
                    <span class="text-gray-600">Keywords</span>
                    <input type="text" wire:model="formKeywords" placeholder="comma separated" class="fi-input mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                </label>

                <label class="block text-sm min-w-0">
                    <span class="text-gray-600">Context key</span>
                    <input
                        type="text"
                        wire:model="formKey"
                        @disabled(! $isCreate)
                        class="fi-input mt-1 block w-full rounded-lg border-gray-300 text-sm font-mono dark:border-gray-600 dark:bg-gray-800 disabled:opacity-60"
                    />
                    @if ($isCreate)
                        <span class="mt-1 block text-xs text-gray-500">Prefix theo group; suffix auto từ title.</span>
                    @endif
                </label>

                <label class="block text-sm min-w-0">
                    <span class="text-gray-600">Sort order</span>
                    <input type="number" wire:model="formSortOrder" class="fi-input mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                </label>
            </div>

            @if ($formPath !== '')
                <div class="mt-3 text-xs text-gray-500 break-all font-mono">{{ $formPath }}</div>
            @endif
        </div>

        <div class="space-y-2">
            <div class="text-sm font-medium text-gray-700 dark:text-gray-200">Help Content</div>
            <div wire:ignore>
                <div
                    id="help-admin-editor-root"
                    data-initial-html='@json($formHtml)'
                    class="min-h-[360px]"
                ></div>
            </div>
        </div>
    </div>

    @if ($previewOpen)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
            style="background: rgba(15, 23, 42, 0.45);"
            wire:click.self="closePreview"
        >
            <div class="w-full max-w-2xl max-h-[85vh] overflow-y-auto rounded-xl bg-white dark:bg-gray-900 shadow-2xl border border-gray-200 dark:border-gray-700">
                <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 dark:border-gray-800">
                    <div>
                        <div class="text-xs uppercase tracking-wide text-gray-500">Help preview</div>
                        <h2 class="text-lg font-semibold">{{ $formTitle }}</h2>
                    </div>
                    <button type="button" class="text-sm text-gray-500 hover:text-gray-800" wire:click="closePreview">Close</button>
                </div>
                <div class="px-5 py-4 space-y-4">
                    @if ($formSummary !== '')
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $formSummary }}</p>
                    @endif
                    <div class="prose prose-sm dark:prose-invert max-w-none help-topic-content__html">
                        {!! $previewHtml !!}
                    </div>
                </div>
            </div>
        </div>
    @endif

    @vite(['resources/js/help-admin/help-admin-editor.jsx'])
</x-filament-panels::page>

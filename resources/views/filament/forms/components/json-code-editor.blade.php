@php($statePath = $getStatePath())

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div x-data="{
        editing: false, valid: false, syntaxError: null, formatted: '', timer: null,
        init() { this.analyze(false); this.editing = this.$refs.editor.value.trim() === ''; },
        escapeHtml(value) { return value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;'); },
        highlight(value) {
            return this.escapeHtml(value).replace(/(&quot;(?:\\u[a-fA-F0-9]{4}|\\[^u]|[^\\&])*&quot;)(\s*:)?|\b(true|false)\b|\b(null)\b|(-?\d+(?:\.\d+)?(?:[eE][+\-]?\d+)?)/g, (match, string, key, bool, nil, number) => {
                if (key) return `<span class='text-sky-300'>${string}</span><span class='text-gray-300'>${key}</span>`;
                if (string) return `<span class='text-emerald-300'>${string}</span>`;
                if (bool) return `<span class='text-amber-300'>${bool}</span>`;
                if (nil) return `<span class='text-rose-300'>${nil}</span>`;
                if (number) return `<span class='text-violet-300'>${number}</span>`;
                return match;
            });
        },
        errorLocation(error, raw) {
            const match = String(error.message || '').match(/position\s+(\d+)/i);
            if (! match) return error.message || 'Không thể phân tích JSON';
            const position = Number(match[1]), before = raw.slice(0, position);
            return `dòng ${before.split('\n').length}, cột ${position - before.lastIndexOf('\n')}: ${error.message}`;
        },
        analyze(validateSchema = true) {
            const raw = this.$refs.editor.value; clearTimeout(this.timer);
            if (raw.trim() === '') { this.valid = false; this.syntaxError = null; this.formatted = ''; return; }
            try {
                this.formatted = JSON.stringify(JSON.parse(raw), null, 2);
                this.valid = true; this.syntaxError = null;
                if (validateSchema) this.timer = setTimeout(() => this.$wire.validateIndustryContextJson(), 250);
            } catch (error) { this.valid = false; this.syntaxError = this.errorLocation(error, raw); this.formatted = ''; }
        },
        formatJson() {
            this.analyze(); if (! this.valid) { this.$refs.editor.focus(); return; }
            this.$refs.editor.value = this.formatted;
            this.$refs.editor.dispatchEvent(new Event('input', { bubbles: true })); this.analyze();
        },
        copyJson() {
            const text = this.$refs.editor.value;
            const fallback = () => { this.$refs.editor.focus(); this.$refs.editor.select(); return document.execCommand('copy'); };
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') navigator.clipboard.writeText(text).catch(fallback); else fallback();
        }
    }" x-init="init()" class="overflow-hidden rounded-xl border border-gray-700 bg-gray-950 shadow-sm ring-primary-600/20 focus-within:border-primary-500 focus-within:ring-2">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-700 bg-gray-900 px-4 py-2">
            <span class="font-mono text-xs font-bold uppercase tracking-wide text-white">JSON</span>
            <div class="flex gap-1">
                <button type="button" x-on:click="copyJson()" class="rounded px-2.5 py-1 text-xs font-semibold text-gray-200 hover:bg-gray-700">Copy</button>
                <button type="button" x-on:click="formatJson()" class="rounded px-2.5 py-1 text-xs font-semibold text-gray-200 hover:bg-gray-700">Format JSON</button>
                <button type="button" x-on:click="editing = ! editing" class="rounded px-2.5 py-1 text-xs font-semibold text-gray-200 hover:bg-gray-700" x-text="editing ? 'Xem trước' : 'Sửa'"></button>
            </div>
        </div>
        <div class="border-b border-gray-800 px-4 py-2 text-sm font-medium">
            <span x-show="valid" class="text-emerald-300">✓ JSON hợp lệ</span>
            <span x-show="syntaxError" class="text-rose-300">✕ JSON không hợp lệ — <span x-text="syntaxError"></span></span>
            <span x-show="! valid && ! syntaxError" class="text-gray-300">Dán hoặc nhập Core Industry Context JSON.</span>
        </div>
        <pre x-show="valid && ! editing" x-html="highlight(formatted)" class="min-h-[32rem] max-h-[70vh] overflow-auto whitespace-pre p-4 font-mono text-sm leading-6 text-gray-100"></pre>
        <textarea x-show="editing || ! valid" x-ref="editor" id="{{ $getId() }}" rows="{{ $getRows() ?? 30 }}" spellcheck="false" data-gramm="false" data-gramm_editor="false" data-enable-grammarly="false" wire:model.live.debounce.300ms="{{ $statePath }}" x-on:input="analyze()" class="block min-h-[32rem] max-h-[70vh] w-full resize-y overflow-auto whitespace-pre border-0 bg-gray-950 p-4 font-mono text-sm leading-6 text-gray-100 caret-white outline-none placeholder:text-gray-400 focus:ring-0"></textarea>
    </div>
</x-dynamic-component>

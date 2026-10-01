<div x-data="{
    copyPrompt() {
        const text = this.$refs.prompt.value;
        let copied = false;

        const fallback = () => {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            try { copied = document.execCommand('copy'); } catch (error) { copied = false; }
            textarea.remove();
            return copied;
        };

        const notify = (success) => {
            const notification = new FilamentNotification().title(success ? 'Đã copy prompt' : 'Không thể copy prompt');
            (success ? notification.success() : notification.danger()).send();
        };

        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(text).then(() => notify(true)).catch(() => notify(fallback()));
        } else {
            notify(fallback());
        }
    }
}" class="space-y-3">
    <textarea x-ref="prompt" readonly rows="28" spellcheck="false" class="block max-h-[70vh] w-full resize-y overflow-auto whitespace-pre rounded-lg border-gray-300 bg-gray-950 p-4 font-mono text-sm leading-6 text-gray-100">{{ $prompt }}</textarea>
    <div class="flex justify-end">
        <x-filament::button type="button" icon="heroicon-o-clipboard" x-on:click="copyPrompt()">Copy Prompt</x-filament::button>
    </div>
</div>

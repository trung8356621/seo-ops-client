<div
    x-data="{
        async copyPrompt() {
            const text = this.$refs.prompt.value;
            let success = false;

            if (text && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                try {
                    await navigator.clipboard.writeText(text);
                    success = true;
                } catch (error) {
                    success = false;
                }
            }

            if (! success && text) {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'fixed';
                textarea.style.left = '-9999px';
                textarea.style.top = '0';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.focus();
                textarea.select();

                try {
                    success = document.execCommand('copy');
                } catch (error) {
                    success = false;
                } finally {
                    textarea.remove();
                }
            }

            const notification = new FilamentNotification()
                .title(success ? 'Đã copy prompt' : 'Không thể copy prompt');
            (success ? notification.success() : notification.danger()).send();
        }
    }"
    class="space-y-4"
>
    <textarea
        x-ref="prompt"
        readonly
        rows="28"
        class="block max-h-[65vh] w-full resize-y overflow-auto rounded-lg border-gray-300 bg-gray-950 p-4 font-mono text-xs text-gray-100 shadow-sm"
    >{{ $prompt }}</textarea>

    <div class="flex justify-end">
        <x-filament::button type="button" icon="heroicon-o-clipboard" x-on:click="copyPrompt()">
            Copy Prompt
        </x-filament::button>
    </div>
</div>

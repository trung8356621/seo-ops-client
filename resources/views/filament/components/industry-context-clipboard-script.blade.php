@once
    <script>
        const decodeIndustryContextBase64 = (encoded) => {
            const bytes = Uint8Array.from(atob(encoded), character => character.charCodeAt(0));

            return new TextDecoder().decode(bytes);
        };

        window.copyIndustryContextPromptFromBase64 = function (encodedPrompt) {
            return window.copyIndustryContextPrompt(decodeIndustryContextBase64(encodedPrompt));
        };

        window.notifyIndustryContextClipboardWarningFromBase64 = function (encodedMessage) {
            new FilamentNotification().title(decodeIndustryContextBase64(encodedMessage)).warning().send();

            return false;
        };

        if (! window.industryContextClipboardListenerInstalled) {
            document.addEventListener('click', function (event) {
                const trigger = event.target.closest('[data-industry-context-action]');
                if (! trigger) return;

                event.preventDefault();
                event.stopImmediatePropagation();

                if (trigger.dataset.industryContextAction === 'copy') {
                    window.copyIndustryContextPromptFromBase64(trigger.dataset.industryContextPayload);
                    return;
                }

                window.notifyIndustryContextClipboardWarningFromBase64(trigger.dataset.industryContextPayload);
            }, true);
            window.industryContextClipboardListenerInstalled = true;
        }

        window.copyIndustryContextPrompt = async function (text) {
            let success = false;
            if (text && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                try { await navigator.clipboard.writeText(text); success = true; } catch (error) { success = false; }
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
                try { success = document.execCommand('copy'); } catch (error) { success = false; } finally { textarea.remove(); }
            }
            const notification = new FilamentNotification().title(success ? 'Đã copy Prompt' : 'Không thể copy Prompt');
            (success ? notification.success() : notification.danger()).send();

            return success;
        };
    </script>
@endonce

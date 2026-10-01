@once
    <script>
        window.copyIndustryContextPrompt = async function (text) {
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
                .title(success ? 'Đã copy Prompt' : 'Không thể copy Prompt');
            (success ? notification.success() : notification.danger()).send();

            return success;
        };
    </script>
@endonce

<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Support\Js;

final class IndustryContextClipboard
{
    public static function copyScript(string $text): string
    {
        $script = <<<'JS'
            window.copyIndustryContextPrompt ??= async function (text) {
                if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                    try {
                        await navigator.clipboard.writeText(text);
                        return true;
                    } catch (error) {
                        // Insecure local origins commonly reject the Clipboard API; use the DOM fallback.
                    }
                }

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
                    return document.execCommand('copy');
                } catch (error) {
                    return false;
                } finally {
                    textarea.remove();
                }
            };

            window.copyIndustryContextPrompt(__PROMPT__).then(function (success) {
                const notification = new FilamentNotification().title(
                    success ? 'Đã copy prompt' : 'Không thể copy prompt'
                );
                (success ? notification.success() : notification.danger()).send();
            });
            JS;

        return str_replace('__PROMPT__', (string) Js::from($text), $script);
    }
}

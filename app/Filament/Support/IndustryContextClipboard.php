<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Support\Js;

final class IndustryContextClipboard
{
    public static function copyScript(string $prompt): string
    {
        return '$event.preventDefault(); $event.stopImmediatePropagation(); '
            .'window.copyIndustryContextPrompt('.Js::from($prompt).');';
    }

    public static function warningScript(string $message): string
    {
        return '$event.preventDefault(); $event.stopImmediatePropagation(); '
            .'new FilamentNotification().title('.Js::from($message).').warning().send();';
    }
}

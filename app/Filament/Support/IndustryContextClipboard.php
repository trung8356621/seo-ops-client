<?php

declare(strict_types=1);

namespace App\Filament\Support;

final class IndustryContextClipboard
{
    /** @return array<string, string> */
    public static function copyAttributes(string $prompt): array
    {
        return [
            'data-industry-context-action' => 'copy',
            'data-industry-context-payload' => base64_encode($prompt),
        ];
    }

    /** @return array<string, string> */
    public static function warningAttributes(string $message): array
    {
        return [
            'data-industry-context-action' => 'warning',
            'data-industry-context-payload' => base64_encode($message),
        ];
    }
}

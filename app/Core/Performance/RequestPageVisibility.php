<?php

declare(strict_types=1);

namespace App\Core\Performance;

use Illuminate\Http\Request;

/**
 * Browser Page Visibility as a scheduling hint (not a security boundary).
 */
final class RequestPageVisibility
{
    public const HEADER = 'X-Document-Hidden';

    public static function isHidden(?Request $request = null): bool
    {
        $request ??= request();
        if (! $request instanceof Request) {
            return false;
        }

        $header = strtolower(trim((string) $request->header(self::HEADER, '')));
        if (in_array($header, ['1', 'true', 'yes', 'hidden'], true)) {
            return true;
        }

        $input = $request->input('document_hidden', $request->input('visibility_hidden'));
        if (is_bool($input)) {
            return $input;
        }

        $raw = strtolower(trim((string) $input));

        return in_array($raw, ['1', 'true', 'yes', 'hidden'], true);
    }
}

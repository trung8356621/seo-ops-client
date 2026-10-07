<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Support;

final class ClientTransferUploadLimits
{
    public static function maxUploadMegabytes(): int
    {
        return max(1, (int) config('client-transfer.max_upload_mb', 2048));
    }

    public static function maxUploadKilobytes(): int
    {
        return self::maxUploadMegabytes() * 1024;
    }

    public static function maxUploadBytes(): int
    {
        return self::maxUploadMegabytes() * 1024 * 1024;
    }

    public static function fileExceedsLimit(string $path): bool
    {
        $size = @filesize($path);

        return is_int($size) && $size > self::maxUploadBytes();
    }

    public static function parseIniSize(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        if ($value === '-1') {
            return PHP_INT_MAX;
        }

        if (preg_match('/^(\d+)\s*([KMG])B?$/i', $value, $matches) !== 1) {
            return max(0, (int) $value);
        }

        $quantity = (int) $matches[1];

        return match (strtoupper($matches[2])) {
            'K' => $quantity * 1024,
            'M' => $quantity * 1024 * 1024,
            'G' => $quantity * 1024 * 1024 * 1024,
            default => $quantity,
        };
    }

    public static function phpEffectiveUploadBytes(?string $uploadMaxFilesize = null, ?string $postMaxSize = null): int
    {
        $upload = self::parseIniSize($uploadMaxFilesize ?? (string) ini_get('upload_max_filesize'));
        $post = self::parseIniSize($postMaxSize ?? (string) ini_get('post_max_size'));
        $upload = $upload <= 0 ? PHP_INT_MAX : $upload;
        $post = $post <= 0 ? PHP_INT_MAX : $post;

        return (int) min($upload, $post);
    }

    public static function phpIniBottleneckWarning(?string $uploadMaxFilesize = null, ?string $postMaxSize = null): ?string
    {
        $configuredBytes = self::maxUploadBytes();
        $effective = self::phpEffectiveUploadBytes($uploadMaxFilesize, $postMaxSize);
        if ($effective >= $configuredBytes) {
            return null;
        }

        $configuredMb = self::maxUploadMegabytes();
        $effectiveMb = round($effective / 1048576, 1);
        $uploadDisplay = trim((string) ($uploadMaxFilesize ?? ini_get('upload_max_filesize')));
        $postDisplay = trim((string) ($postMaxSize ?? ini_get('post_max_size')));

        return "PHP currently allows about {$effectiveMb} MB per request (upload_max_filesize={$uploadDisplay}, post_max_size={$postDisplay}), which is below CLIENT_TRANSFER_MAX_UPLOAD_MB={$configuredMb}. Raise those PHP/web-server limits or the browser upload will fail before Laravel validation.";
    }
}

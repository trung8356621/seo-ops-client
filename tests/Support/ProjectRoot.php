<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

/** Resolve omnichannel-client root after repo split. */
final class ProjectRoot
{
    public static function path(): string
    {
        if (function_exists('app')) {
            try {
                $app = app();
                if ($app instanceof Application) {
                    return $app->basePath();
                }
            } catch (\Throwable) {
                // pure PHPUnit ? fall through
            }
        }

        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            $dir = dirname($dir);
            if (is_file($dir.DIRECTORY_SEPARATOR.'artisan') && is_dir($dir.DIRECTORY_SEPARATOR.'addons')) {
                return $dir;
            }
        }

        throw new RuntimeException('Unable to locate omnichannel-client project root.');
    }

    public static function addonsPath(): string
    {
        $configured = getenv('OMNICHANNEL_ADDONS_PATH');
        if (is_string($configured) && $configured !== '' && is_dir($configured)) {
            return $configured;
        }

        return self::path().DIRECTORY_SEPARATOR.'addons';
    }
}
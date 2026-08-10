<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Resolve historical SeoContentAi-relative paths to peer-addon / compat locations.
 */
final class LegacyAddonPath
{
    public static function resolve(string $relative): string
    {
        $normalizedRelative = ltrim(str_replace('\\', '/', $relative), '/');
        $root = ProjectRoot::path();
        $addonsRoot = ProjectRoot::addonsPath();

        $candidates = [
            $root.'/app/Addons/SeoContentAi/'.$normalizedRelative,
            $addonsRoot.'/seo-content-ai-compat/'.$normalizedRelative,
        ];
        foreach ($candidates as $legacyPath) {
            $legacyPath = str_replace('/', DIRECTORY_SEPARATOR, $legacyPath);
            if (is_file($legacyPath)) {
                return $legacyPath;
            }
        }

        foreach (glob($addonsRoot.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $addonDir) {
            foreach (['/src/', '/resources/js/', '/resources/', '/database/migrations/', '/'] as $suffix) {
                $candidate = $addonDir.$suffix.$normalizedRelative;
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        $basename = basename($normalizedRelative);
        foreach (glob($addonsRoot.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $addonDir) {
            foreach ([$addonDir.'/src', $addonDir.'/resources/js', $addonDir] as $searchRoot) {
                if (! is_dir($searchRoot)) {
                    continue;
                }
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($searchRoot, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getFilename() === $basename) {
                        return $file->getPathname();
                    }
                }
            }
        }

        throw new RuntimeException(sprintf(
            'Unable to locate "%s" under seo-content-ai-compat or addons/*.',
            $relative
        ));
    }

    public static function read(string $relative): string
    {
        $path = self::resolve($relative);
        $body = file_get_contents($path);
        if (! is_string($body)) {
            throw new RuntimeException('Failed reading '.$path);
        }

        return $body;
    }
}
<?php

declare(strict_types=1);

namespace App\System\Support;

/**
 * Resolves strangler mode per capability → module → default.
 */
final class CapabilityModeResolver
{
    public function resolve(?string $capability, string $module): SystemExecutionMode
    {
        $capability = $capability !== null ? trim($capability) : '';
        if ($capability !== '') {
            // Capability keys contain dots (e.g. article.content.generate). Laravel's
            // config('a.b.c') walks nested arrays, so flat map entries in
            // config/system.php must be read via the capabilities bag first.
            $capMode = SystemExecutionMode::tryParse($this->capabilityModeString($capability));
            if ($capMode instanceof SystemExecutionMode) {
                return $capMode;
            }
        }

        $moduleMode = SystemExecutionMode::tryParse($this->configString("system.modules.{$module}"));
        if ($moduleMode instanceof SystemExecutionMode) {
            return $moduleMode;
        }

        return SystemExecutionMode::tryParse($this->configString('system.default_mode', 'legacy'))
            ?? SystemExecutionMode::Legacy;
    }

    private function capabilityModeString(string $capability): string
    {
        if (! function_exists('config')) {
            return '';
        }

        try {
            $caps = config('system.capabilities', []);
            if (! is_array($caps)) {
                return '';
            }

            // Production: flat key from config/system.php
            if (isset($caps[$capability]) && is_string($caps[$capability]) && trim($caps[$capability]) !== '') {
                return trim($caps[$capability]);
            }

            // Tests / nested config(['system.capabilities.article.content.generate' => ...])
            $nested = data_get($caps, $capability);
            if (is_string($nested) && trim($nested) !== '') {
                return trim($nested);
            }
        } catch (\Throwable) {
            return '';
        }

        return '';
    }

    private function configString(string $key, string $default = ''): string
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            return (string) config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}

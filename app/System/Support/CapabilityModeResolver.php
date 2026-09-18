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
            $capMode = SystemExecutionMode::tryParse($this->configString("system.capabilities.{$capability}"));
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

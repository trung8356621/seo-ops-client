<?php

declare(strict_types=1);

namespace App\Core\Addon;

/**
 * Validates addon deployment readiness before registering routes or booting providers.
 *
 * Ensures partially developed or broken addons are safely isolated and cannot crash
 * global Laravel bootstrap or cause site-wide HTTP 500 errors.
 */
final class AddonReadinessValidator
{
    /**
     * @param  list<string>|null  $deploymentReadySlugs  Optional server allowlist override
     */
    public function __construct(
        private readonly ?AddonDependencyValidator $dependencyValidator = null,
        private readonly ?array $deploymentReadySlugs = null,
    ) {}

    public function validateManifest(AddonManifest $manifest): AddonReadinessResult
    {
        $slug = $manifest->slug;
        if (trim($slug) === '') {
            return AddonReadinessResult::notReady('unknown', 'Manifest missing slug.');
        }

        // 1. Explicit deployment readiness check & server allowlist enforcement
        $readinessCheck = $this->checkDeploymentReadiness($manifest);
        if ($readinessCheck !== null) {
            return $readinessCheck;
        }

        // 2. Package / namespace autoload availability
        if (! class_exists($manifest->provider)) {
            return AddonReadinessResult::notReady($slug, "Main provider class [{$manifest->provider}] not autoloadable.");
        }

        if ($manifest->panelProvider !== null && ! class_exists($manifest->panelProvider)) {
            return AddonReadinessResult::notReady($slug, "Panel provider class [{$manifest->panelProvider}] not autoloadable.");
        }

        foreach ($manifest->extraProviders as $providerClass) {
            if (! class_exists($providerClass)) {
                return AddonReadinessResult::notReady($slug, "Extra provider class [{$providerClass}] not autoloadable.");
            }
        }

        // 3. Declared dependencies validation
        if ($this->dependencyValidator !== null && ! empty($manifest->requires)) {
            $peers = [];
            if (function_exists('app') && app()->bound(AddonRegistry::class)) {
                $peers = app(AddonRegistry::class)->all();
            }
            if (empty($peers)) {
                $peers = [$manifest];
            } else {
                $hasManifest = false;
                foreach ($peers as $peer) {
                    if ($peer->slug === $manifest->slug) {
                        $hasManifest = true;
                        break;
                    }
                }
                if (! $hasManifest) {
                    $peers[] = $manifest;
                }
            }
            $violations = $this->dependencyValidator->validate($peers);
            $slugViolations = array_values(array_filter(
                $violations,
                static fn (string $v): bool => str_contains($v, "[{$manifest->slug}]")
            ));
            if (! empty($slugViolations)) {
                return AddonReadinessResult::notReady($slug, 'Missing declared dependencies.', $slugViolations);
            }
        }

        return AddonReadinessResult::ready($slug);
    }

    public function validateRouteFile(string $slug, string $routeFile): AddonReadinessResult
    {
        if (! is_file($routeFile)) {
            return AddonReadinessResult::notReady($slug, "Route file [{$routeFile}] does not exist.");
        }

        // Find and validate manifest if present
        $manifestPath = dirname($routeFile, 2).DIRECTORY_SEPARATOR.'addon.json';
        if (is_file($manifestPath)) {
            $raw = json_decode((string) file_get_contents($manifestPath), true);
            if (! is_array($raw)) {
                return AddonReadinessResult::notReady($slug, "Invalid JSON in addon manifest [{$manifestPath}].");
            }
            try {
                $manifest = AddonManifest::fromArray($raw, dirname($manifestPath));
                $manifestResult = $this->validateManifest($manifest);
                if (! $manifestResult->isReady()) {
                    return $manifestResult;
                }
            } catch (\Throwable $e) {
                return AddonReadinessResult::notReady($slug, 'Manifest validation error: '.$e->getMessage());
            }
        } else {
            // No manifest: check if slug is in deployment_ready allowlist
            $allowlist = array_fill_keys($this->getServerAllowlist(), true);
            if (! isset($allowlist[$slug])) {
                return AddonReadinessResult::notReady($slug, "Addon [{$slug}] missing manifest and not approved in server deployment allowlist.");
            }
        }

        // Validate route file contents (classes, action signatures, callable targets)
        $content = (string) file_get_contents($routeFile);
        $routeViolations = $this->inspectRouteContent($content, $routeFile);
        if (! empty($routeViolations)) {
            return AddonReadinessResult::notReady($slug, 'Route validation failed.', $routeViolations);
        }

        return AddonReadinessResult::ready($slug);
    }

    /**
     * Inspect route file content to ensure referenced classes, actions, and middlewares exist.
     *
     * @return list<string> Violations
     */
    public function inspectRouteContent(string $content, string $filePath = ''): array
    {
        $violations = [];
        $imports = $this->extractImports($content);

        // 1. Controller actions: [ControllerClass::class, 'methodName']
        if (preg_match_all('/\[\s*([A-Za-z0-9_\\\\]+)::class\s*,\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*\]/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $classRef = $match[1];
                $method = $match[2];
                $fqcn = $this->resolveFqcn($classRef, $imports);

                if (! class_exists($fqcn)) {
                    $violations[] = "Controller class [{$fqcn}] does not exist in autoloader.";
                    continue;
                }

                if (! method_exists($fqcn, $method)) {
                    $violations[] = "Controller method [{$fqcn}::{$method}] does not exist.";
                }
            }
        }

        // 2. Invokable controllers: Route::<verb>(..., ControllerClass::class)
        if (preg_match_all('/Route::(?:get|post|put|patch|delete|any|options)\s*\([^,]+,\s*([A-Za-z0-9_\\\\]+)::class\s*[\),]/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $classRef = $match[1];
                $methodOrInvokable = $match[1];
                $fqcn = $this->resolveFqcn($classRef, $imports);

                if (! class_exists($fqcn)) {
                    $violations[] = "Invokable controller class [{$fqcn}] does not exist in autoloader.";
                    continue;
                }

                if (! method_exists($fqcn, '__invoke')) {
                    $violations[] = "Controller [{$fqcn}] is not invokable (missing __invoke).";
                }
            }
        }

        // 3. String actions: 'ControllerClass@method'
        if (preg_match_all('/[\'"]([A-Za-z0-9_\\\\]+)@([A-Za-z0-9_]+)[\'"]/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $classRef = $match[1];
                $method = $match[2];
                $fqcn = $this->resolveFqcn($classRef, $imports);

                if (! class_exists($fqcn)) {
                    $violations[] = "Controller class [{$fqcn}] does not exist in autoloader.";
                    continue;
                }

                if (! method_exists($fqcn, $method)) {
                    $violations[] = "Controller action [{$fqcn}::{$method}] does not exist.";
                }
            }
        }

        // 4. Custom middleware classes: [..., SomeMiddleware::class, ...]
        if (preg_match_all('/middleware\s*\(\s*\[([^\]]+)\]/s', $content, $groups)) {
            foreach ($groups[1] as $group) {
                if (preg_match_all('/([A-Za-z0-9_\\\\]+)::class/', $group, $mwMatches)) {
                    foreach ($mwMatches[1] as $mwRef) {
                        $fqcn = $this->resolveFqcn($mwRef, $imports);
                        if (! class_exists($fqcn)) {
                            $violations[] = "Middleware class [{$fqcn}] does not exist in autoloader.";
                        }
                    }
                }
            }
        }

        return array_values(array_unique($violations));
    }

    public function checkDeploymentReadiness(AddonManifest $manifest): ?AddonReadinessResult
    {
        $slug = $manifest->slug;

        // 1. Manifest explicit opt-out: if package explicitly declares deployment_ready: false, fail immediately.
        if (array_key_exists('deployment_ready', $manifest->raw) && ! (bool) $manifest->raw['deployment_ready']) {
            return AddonReadinessResult::notReady($slug, "Addon [{$slug}] manifest explicitly marks deployment_ready as false.");
        }

        // 2. Server authority: package self-declaration (deployment_ready: true) alone is not enough.
        // The host environment must have the final decision via the deployment allowlist.
        $allowlist = array_fill_keys($this->getServerAllowlist(), true);
        if (! isset($allowlist[$slug])) {
            return AddonReadinessResult::notReady(
                $slug,
                "Addon [{$slug}] is not approved in server deployment allowlist (addons.deployment_ready_slugs)."
            );
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function getServerAllowlist(): array
    {
        if ($this->deploymentReadySlugs !== null) {
            return $this->deploymentReadySlugs;
        }

        if (function_exists('config')) {
            return (array) config('addons.deployment_ready_slugs', []);
        }

        return [];
    }

    /**
     * @return array<string, string> alias/basename => FQCN
     */
    private function extractImports(string $content): array
    {
        $imports = [];
        if (preg_match_all('/use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fqcn = ltrim($match[1], '\\');
                $alias = ! empty($match[2]) ? $match[2] : (basename(str_replace('\\', '/', $fqcn)));
                $imports[$alias] = $fqcn;
            }
        }

        return $imports;
    }

    /**
     * @param  array<string, string>  $imports
     */
    private function resolveFqcn(string $classRef, array $imports): string
    {
        $classRef = ltrim($classRef, '\\');
        if (isset($imports[$classRef])) {
            return $imports[$classRef];
        }

        // Check if prefix matches an import (e.g. use Foo\Bar; Bar\Baz::class)
        $parts = explode('\\', $classRef, 2);
        if (count($parts) === 2 && isset($imports[$parts[0]])) {
            return $imports[$parts[0]].'\\'.$parts[1];
        }

        return $classRef;
    }
}

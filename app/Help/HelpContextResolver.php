<?php

declare(strict_types=1);

namespace App\Help;

/**
 * Resolves the Help drawer context.
 *
 * Priority:
 * 1. explicit contextual topic/context (handled by the drawer open payload, not here)
 * 2. SEO page-specific contexts
 * 3. Admin panel context
 * 4. Seeding panel context
 * 5. generic system fallback
 *
 * The same step list is shipped as `context_resolution` for the client drawer script.
 */
final class HelpContextResolver
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function steps(): array
    {
        return [
            ['id' => 'syncQueue', 'requiresSearch' => '[?&]tab=queue\\b'],
            ['id' => 'categories', 'requiresSearch' => '[?&]tab=categories\\b'],
            ['id' => 'articleEditor'],
            ['id' => 'media'],
            ['id' => 'seo'],
            ['id' => 'settings'],
            ['id' => 'articles'],
            ['id' => 'dashboard'],
            ['id' => 'articleEditor', 'bodyClass' => 'article-editor-page'],
            ['id' => 'admin', 'panelIds' => ['admin']],
            ['id' => 'seeding', 'panelIds' => ['seeding']],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $contexts
     * @return array<string, mixed>
     */
    public static function resolve(
        array $contexts,
        ?string $routeName,
        string $path,
        string $search = '',
        ?string $panelId = null,
        bool $articleEditorBody = false,
    ): array {
        $routeName = trim((string) $routeName);
        $fullPath = $path.$search;

        foreach (self::steps() as $step) {
            $id = (string) ($step['id'] ?? '');
            $context = $contexts[$id] ?? null;
            if (! is_array($context)) {
                continue;
            }

            if (isset($step['bodyClass'])) {
                if ($articleEditorBody && $step['bodyClass'] === 'article-editor-page') {
                    return $context;
                }

                continue;
            }

            $requiresSearch = (string) ($step['requiresSearch'] ?? '');
            if ($requiresSearch !== '' && ! self::matchesPattern($requiresSearch, $search)) {
                continue;
            }

            $panelIds = is_array($step['panelIds'] ?? null) ? $step['panelIds'] : null;
            if ($panelIds !== null) {
                $panelHit = is_string($panelId) && in_array($panelId, $panelIds, true);
                if ($panelHit || self::matchesContext($context, $routeName, $fullPath)) {
                    return $context;
                }

                continue;
            }

            if (self::matchesContext($context, $routeName, $fullPath)) {
                return $context;
            }
        }

        $system = $contexts['system'] ?? null;
        if (is_array($system)) {
            return $system;
        }

        return [
            'id' => 'system',
            'modalTitle' => 'Hướng dẫn hệ thống',
            'defaultGroupId' => 'overview',
            'routeNames' => [],
            'pathPatterns' => [],
            'groupIds' => ['overview'],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function matchesContext(array $context, string $routeName, string $fullPath): bool
    {
        $names = is_array($context['routeNames'] ?? null) ? $context['routeNames'] : [];
        foreach ($names as $pattern) {
            if (self::routeNameMatches($routeName, (string) $pattern)) {
                return true;
            }
        }

        $paths = is_array($context['pathPatterns'] ?? null) ? $context['pathPatterns'] : [];
        foreach ($paths as $pattern) {
            if (self::matchesPattern((string) $pattern, $fullPath)) {
                return true;
            }
        }

        return false;
    }

    private static function routeNameMatches(string $routeName, string $pattern): bool
    {
        if ($routeName === '' || $pattern === '') {
            return false;
        }

        if (str_ends_with($pattern, '*')) {
            $prefix = substr($pattern, 0, -1);

            return $routeName === $prefix || str_starts_with($routeName, $prefix);
        }

        return $routeName === $pattern;
    }

    private static function matchesPattern(string $pattern, string $subject): bool
    {
        if ($pattern === '') {
            return false;
        }

        $result = @preg_match('#'.$pattern.'#u', $subject);

        return $result === 1;
    }
}

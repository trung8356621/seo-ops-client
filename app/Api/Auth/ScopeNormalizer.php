<?php

declare(strict_types=1);

namespace App\Api\Auth;

use InvalidArgumentException;

/**
 * Normalize flat string scope lists before persistence.
 */
final class ScopeNormalizer
{
    private const SCOPE_PATTERN = '/^[a-z0-9][a-z0-9_.:*-]*$/i';

    /**
     * @param  mixed  $scopes
     * @return list<string>
     */
    public function normalize(mixed $scopes): array
    {
        if ($scopes === null) {
            return [];
        }

        if (is_string($scopes)) {
            $scopes = preg_split('/[\s,]+/', $scopes) ?: [];
        }

        if (! is_array($scopes)) {
            throw new InvalidArgumentException('Scopes must be a flat list of strings.');
        }

        $out = [];
        foreach ($scopes as $item) {
            if ($item === null || $item === '') {
                continue;
            }
            if (! is_string($item) && ! is_int($item) && ! is_float($item)) {
                throw new InvalidArgumentException('Scopes must be a flat list of strings.');
            }
            if (is_array($item)) {
                throw new InvalidArgumentException('Scopes must be a flat list of strings.');
            }

            $value = trim((string) $item);
            if ($value === '') {
                continue;
            }
            if ($value !== '*' && preg_match(self::SCOPE_PATTERN, $value) !== 1) {
                throw new InvalidArgumentException('Malformed scope: '.$value);
            }
            $out[] = $value === '*' ? '*' : strtolower($value);
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }
}

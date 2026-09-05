<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Resolves form password draft semantics for ServiceDatabaseConnection.
 *
 * NEW + blank password  → intentional empty password
 * EXISTING + blank      → keep stored password
 * clear_password=true   → force empty password
 * non-empty field       → replace password
 */
final class ServiceDatabasePasswordIntent
{
    public const ACTION_SET = 'set';

    public const ACTION_KEEP = 'keep';

    public const ACTION_CLEAR = 'clear';

    /**
     * @param  array<string, mixed>  $state
     * @return array{action: string, plain: ?string}
     */
    public static function fromFormState(array $state, bool $hasExistingConnection): array
    {
        $clear = (bool) ($state['clear_password'] ?? false);
        $plain = (string) ($state['password'] ?? '');

        if ($clear) {
            return ['action' => self::ACTION_CLEAR, 'plain' => null];
        }

        if ($plain !== '') {
            return ['action' => self::ACTION_SET, 'plain' => $plain];
        }

        if (! $hasExistingConnection) {
            // First-time blank = no password (valid for local MySQL root).
            return ['action' => self::ACTION_SET, 'plain' => null];
        }

        return ['action' => self::ACTION_KEEP, 'plain' => null];
    }

    /**
     * Password string to use for an explicit form Test Connection (no fallback sources).
     */
    public static function plainForTest(array $intent, ?string $storedPlainPassword): string
    {
        return match ($intent['action']) {
            self::ACTION_CLEAR => '',
            self::ACTION_SET => (string) ($intent['plain'] ?? ''),
            self::ACTION_KEEP => (string) ($storedPlainPassword ?? ''),
            default => '',
        };
    }
}

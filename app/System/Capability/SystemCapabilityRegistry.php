<?php

declare(strict_types=1);

namespace App\System\Capability;

use InvalidArgumentException;
use RuntimeException;

/**
 * System-owned capability registry. Addons register; System never imports domain handlers by class.
 */
final class SystemCapabilityRegistry
{
    /** @var array<string, SystemCapabilityDefinition> */
    private array $definitions = [];

    public function register(SystemCapabilityDefinition $definition): void
    {
        $key = trim($definition->key);
        if ($key === '') {
            throw new InvalidArgumentException('Capability key must not be empty.');
        }

        if (isset($this->definitions[$key])) {
            throw new InvalidArgumentException("Capability [{$key}] already registered.");
        }

        $this->definitions[$key] = new SystemCapabilityDefinition(
            key: $key,
            owner: $definition->owner,
            handler: $definition->handler,
            sideEffectFree: $definition->sideEffectFree,
        );
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[trim($key)]);
    }

    public function definition(string $key): ?SystemCapabilityDefinition
    {
        return $this->definitions[trim($key)] ?? null;
    }

    public function ownerOf(string $key): ?string
    {
        return $this->definitions[trim($key)]?->owner;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->definitions);
    }

    public function resolveHandler(string $key): SystemCapabilityHandler
    {
        $definition = $this->definition($key);
        if ($definition === null) {
            throw new RuntimeException("Capability [{$key}] is not registered.");
        }

        $handler = $definition->handler;
        if ($handler instanceof SystemCapabilityHandler) {
            return $handler;
        }

        $resolved = app($handler);
        if (! $resolved instanceof SystemCapabilityHandler) {
            throw new RuntimeException("Capability [{$key}] handler must implement SystemCapabilityHandler.");
        }

        return $resolved;
    }
}

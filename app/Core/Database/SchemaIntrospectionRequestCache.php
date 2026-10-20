<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Request-scoped memo for Schema::hasTable / getColumns.
 * Collapses identical information_schema introspection within one request.
 */
final class SchemaIntrospectionRequestCache
{
    /** @var array<string, bool> */
    private array $tables = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $columns = [];

    public function hasTable(string $key, callable $resolve): bool
    {
        if (array_key_exists($key, $this->tables)) {
            return $this->tables[$key];
        }

        return $this->tables[$key] = (bool) $resolve();
    }

    /**
     * @param  callable(): list<array<string, mixed>>  $resolve
     * @return list<array<string, mixed>>
     */
    public function columns(string $key, callable $resolve): array
    {
        if (array_key_exists($key, $this->columns)) {
            return $this->columns[$key];
        }

        return $this->columns[$key] = $resolve();
    }

    public function forget(string $key): void
    {
        unset($this->tables[$key], $this->columns[$key]);
    }

    public function flush(): void
    {
        $this->tables = [];
        $this->columns = [];
    }

    public function tableHits(): int
    {
        return count($this->tables);
    }

    public function columnListingHits(): int
    {
        return count($this->columns);
    }
}

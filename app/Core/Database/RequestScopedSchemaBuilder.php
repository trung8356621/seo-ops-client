<?php

declare(strict_types=1);

namespace App\Core\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\MariaDbBuilder;
use Illuminate\Database\Schema\MySqlBuilder;
use Illuminate\Database\Schema\SQLiteBuilder;

/**
 * Schema builders that memoize hasTable / getColumns for the current request.
 */
final class RequestScopedSchemaBuilder
{
    public static function register(): void
    {
        if (! app()->bound(SchemaIntrospectionRequestCache::class)) {
            app()->scoped(SchemaIntrospectionRequestCache::class);
        }

        Connection::resolverFor('mysql', static function ($connection, $database, $prefix, $config) {
            return new class($connection, $database, $prefix, $config) extends MySqlConnection
            {
                public function getSchemaBuilder(): Builder
                {
                    if (is_null($this->schemaGrammar)) {
                        $this->useDefaultSchemaGrammar();
                    }

                    return new RequestScopedMySqlSchemaBuilder($this);
                }
            };
        });

        Connection::resolverFor('mariadb', static function ($connection, $database, $prefix, $config) {
            return new class($connection, $database, $prefix, $config) extends MariaDbConnection
            {
                public function getSchemaBuilder(): Builder
                {
                    if (is_null($this->schemaGrammar)) {
                        $this->useDefaultSchemaGrammar();
                    }

                    return new RequestScopedMariaDbSchemaBuilder($this);
                }
            };
        });

        Connection::resolverFor('sqlite', static function ($connection, $database, $prefix, $config) {
            return new class($connection, $database, $prefix, $config) extends SQLiteConnection
            {
                public function getSchemaBuilder(): Builder
                {
                    if (is_null($this->schemaGrammar)) {
                        $this->useDefaultSchemaGrammar();
                    }

                    return new RequestScopedSqliteSchemaBuilder($this);
                }
            };
        });

        app()->terminating(static function (): void {
            if (app()->bound(SchemaIntrospectionRequestCache::class)) {
                app(SchemaIntrospectionRequestCache::class)->flush();
            }
        });
    }

    /**
     * Drop cached entries after schema mutations so dynamic compatibility stays correct.
     */
    public static function forgetTable(Connection $connection, string $table): void
    {
        if (! app()->bound(SchemaIntrospectionRequestCache::class)) {
            return;
        }

        app(SchemaIntrospectionRequestCache::class)->forget(self::key($connection, $table));
    }

    public static function key(Connection $connection, string $table): string
    {
        return $connection->getName()."\0".$table;
    }

    public static function cache(): ?SchemaIntrospectionRequestCache
    {
        try {
            if (! function_exists('app') || ! app()->bound(SchemaIntrospectionRequestCache::class)) {
                return null;
            }

            return app(SchemaIntrospectionRequestCache::class);
        } catch (\Throwable) {
            return null;
        }
    }
}

/**
 * @internal
 */
final class RequestScopedMySqlSchemaBuilder extends MySqlBuilder
{
    use RequestScopesSchemaIntrospection;
}

/**
 * @internal
 */
final class RequestScopedMariaDbSchemaBuilder extends MariaDbBuilder
{
    use RequestScopesSchemaIntrospection;
}

/**
 * @internal
 */
final class RequestScopedSqliteSchemaBuilder extends SQLiteBuilder
{
    use RequestScopesSchemaIntrospection;
}

/**
 * @internal
 */
trait RequestScopesSchemaIntrospection
{
    public function hasTable($table)
    {
        $cache = RequestScopedSchemaBuilder::cache();
        if ($cache === null) {
            return parent::hasTable($table);
        }

        $key = RequestScopedSchemaBuilder::key($this->connection, (string) $table);

        return $cache->hasTable($key, fn (): bool => parent::hasTable($table));
    }

    public function getColumns($table)
    {
        $cache = RequestScopedSchemaBuilder::cache();
        if ($cache === null) {
            return parent::getColumns($table);
        }

        $key = RequestScopedSchemaBuilder::key($this->connection, (string) $table);

        return $cache->columns($key, fn (): array => parent::getColumns($table));
    }

    public function create($table, $callback)
    {
        RequestScopedSchemaBuilder::forgetTable($this->connection, (string) $table);

        parent::create($table, $callback);
    }

    public function drop($table)
    {
        parent::drop($table);
        RequestScopedSchemaBuilder::forgetTable($this->connection, (string) $table);
    }

    public function dropIfExists($table)
    {
        parent::dropIfExists($table);
        RequestScopedSchemaBuilder::forgetTable($this->connection, (string) $table);
    }

    public function rename($from, $to)
    {
        parent::rename($from, $to);
        RequestScopedSchemaBuilder::forgetTable($this->connection, (string) $from);
        RequestScopedSchemaBuilder::forgetTable($this->connection, (string) $to);
    }

    public function table($table, $callback)
    {
        RequestScopedSchemaBuilder::forgetTable($this->connection, (string) $table);

        parent::table($table, $callback);
    }
}

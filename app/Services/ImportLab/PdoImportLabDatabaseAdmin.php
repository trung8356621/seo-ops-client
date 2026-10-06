<?php

declare(strict_types=1);

namespace App\Services\ImportLab;

use PDO;
use RuntimeException;

final class PdoImportLabDatabaseAdmin implements ImportLabDatabaseAdmin
{
    public function recreate(array $connections): void
    {
        $prepared = [];
        foreach ($connections as $connection) {
            $database = (string) ($connection['database'] ?? '');
            $charset = (string) ($connection['charset'] ?? 'utf8mb4');
            $collation = (string) ($connection['collation'] ?? 'utf8mb4_unicode_ci');
            if (($connection['driver'] ?? null) !== 'mysql') {
                throw new RuntimeException('Import-lab reset requires MySQL connections.');
            }
            $this->assertIdentifier($database, 'database');
            $this->assertIdentifier($charset, 'charset');
            $this->assertIdentifier($collation, 'collation');

            $prepared[] = [new PDO(
                $this->serverDsn($connection, $charset),
                (string) ($connection['username'] ?? ''),
                (string) ($connection['password'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            ), $database, $charset, $collation];
        }

        foreach ($prepared as [$pdo, $database, $charset, $collation]) {
            $quoted = '`'.str_replace('`', '``', $database).'`';
            $pdo->exec("DROP DATABASE IF EXISTS {$quoted}");
            $pdo->exec("CREATE DATABASE {$quoted} CHARACTER SET {$charset} COLLATE {$collation}");
        }
    }

    /** @param array<string, mixed> $connection */
    private function serverDsn(array $connection, string $charset): string
    {
        $socket = trim((string) ($connection['unix_socket'] ?? ''));
        if ($socket !== '') {
            return "mysql:unix_socket={$socket};charset={$charset}";
        }

        return 'mysql:host='.trim((string) ($connection['host'] ?? '127.0.0.1'))
            .';port='.trim((string) ($connection['port'] ?? '3306')).';charset='.$charset;
    }

    private function assertIdentifier(string $value, string $label): void
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_]+$/D', $value) !== 1) {
            throw new RuntimeException("Unsafe MySQL {$label} identifier [{$value}].");
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Installation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

final class DatabaseConfigurator
{
    public function __construct(
        private readonly InstallationStateManager $stateManager,
    ) {}

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{ok: bool, message: string, details?: array<string, mixed>}
     */
    public function testAndConfigure(array $inputs): array
    {
        $coreHost = trim((string) ($inputs['core_host'] ?? $inputs['host'] ?? '127.0.0.1'));
        $corePort = (int) ($inputs['core_port'] ?? $inputs['port'] ?? 3306);
        $coreUser = trim((string) ($inputs['core_username'] ?? $inputs['username'] ?? 'root'));
        $corePass = (string) ($inputs['core_password'] ?? $inputs['password'] ?? '');
        $coreDb = trim((string) ($inputs['core_database'] ?? $inputs['db_client'] ?? ''));

        $seoInherit = filter_var($inputs['seo_inherit_core'] ?? (! isset($inputs['seo_host'])), FILTER_VALIDATE_BOOLEAN);
        $seoHost = $seoInherit ? $coreHost : trim((string) ($inputs['seo_host'] ?? $coreHost));
        $seoPort = $seoInherit ? $corePort : (int) ($inputs['seo_port'] ?? $corePort);
        $seoUser = $seoInherit ? $coreUser : trim((string) ($inputs['seo_username'] ?? $coreUser));
        $seoPass = $seoInherit ? $corePass : (string) ($inputs['seo_password'] ?? $corePass);
        $seoDb = trim((string) ($inputs['seo_database'] ?? $inputs['db_seo'] ?? ''));

        $seedingInherit = filter_var($inputs['seeding_inherit_core'] ?? (! isset($inputs['seeding_host'])), FILTER_VALIDATE_BOOLEAN);
        $seedingHost = $seedingInherit ? $coreHost : trim((string) ($inputs['seeding_host'] ?? $coreHost));
        $seedingPort = $seedingInherit ? $corePort : (int) ($inputs['seeding_port'] ?? $corePort);
        $seedingUser = $seedingInherit ? $coreUser : trim((string) ($inputs['seeding_username'] ?? $coreUser));
        $seedingPass = $seedingInherit ? $corePass : (string) ($inputs['seeding_password'] ?? $corePass);
        $seedingDb = trim((string) ($inputs['seeding_database'] ?? $inputs['db_seeding'] ?? ''));

        $saInherit = filter_var($inputs['social_automation_inherit_core'] ?? (! isset($inputs['social_automation_host'])), FILTER_VALIDATE_BOOLEAN);
        $saHost = $saInherit ? $coreHost : trim((string) ($inputs['social_automation_host'] ?? $coreHost));
        $saPort = $saInherit ? $corePort : (int) ($inputs['social_automation_port'] ?? $corePort);
        $saUser = $saInherit ? $coreUser : trim((string) ($inputs['social_automation_username'] ?? $coreUser));
        $saPass = $saInherit ? $corePass : (string) ($inputs['social_automation_password'] ?? $corePass);
        $saDb = trim((string) ($inputs['social_automation_database'] ?? $inputs['db_social_automation'] ?? ''));

        if ($coreDb === '' || $seoDb === '' || $seedingDb === '' || $saDb === '') {
            return [
                'ok' => false,
                'message' => 'All four database names (Client Core, SEO, Seeding, Social Automation) are required.',
            ];
        }

        $databases = [
            'mysql' => [
                'label' => 'Client Core',
                'database' => $coreDb,
                'host' => $coreHost,
                'port' => $corePort,
                'username' => $coreUser,
                'password' => $corePass,
            ],
            'omi_seo_ai' => [
                'label' => 'SEO Content AI',
                'database' => $seoDb,
                'host' => $seoHost,
                'port' => $seoPort,
                'username' => $seoUser,
                'password' => $seoPass,
            ],
            'omi_seeding' => [
                'label' => 'Seeding',
                'database' => $seedingDb,
                'host' => $seedingHost,
                'port' => $seedingPort,
                'username' => $seedingUser,
                'password' => $seedingPass,
            ],
            'omi_social_automation' => [
                'label' => 'Social Automation',
                'database' => $saDb,
                'host' => $saHost,
                'port' => $saPort,
                'username' => $saUser,
                'password' => $saPass,
            ],
        ];

        // 1. Verify connections and safety
        foreach ($databases as $key => $target) {
            $check = $this->verifyDatabaseTarget($target);
            if (! $check['ok']) {
                return $check;
            }
        }

        // 2. Persist to .env atomically
        $this->updateEnvironmentVariables([
            'DB_HOST' => $databases['mysql']['host'],
            'DB_PORT' => (string) $databases['mysql']['port'],
            'DB_DATABASE' => $databases['mysql']['database'],
            'DB_USERNAME' => $databases['mysql']['username'],
            'DB_PASSWORD' => $databases['mysql']['password'],

            'SEO_DB_HOST' => $databases['omi_seo_ai']['host'],
            'SEO_DB_PORT' => (string) $databases['omi_seo_ai']['port'],
            'SEO_DB_DATABASE' => $databases['omi_seo_ai']['database'],
            'SEO_DB_USERNAME' => $databases['omi_seo_ai']['username'],
            'SEO_DB_PASSWORD' => $databases['omi_seo_ai']['password'],

            'SEEDING_DB_HOST' => $databases['omi_seeding']['host'],
            'SEEDING_DB_PORT' => (string) $databases['omi_seeding']['port'],
            'SEEDING_DB_DATABASE' => $databases['omi_seeding']['database'],
            'SEEDING_DB_USERNAME' => $databases['omi_seeding']['username'],
            'SEEDING_DB_PASSWORD' => $databases['omi_seeding']['password'],

            'SOCIAL_AUTOMATION_DB_HOST' => $databases['omi_social_automation']['host'],
            'SOCIAL_AUTOMATION_DB_PORT' => (string) $databases['omi_social_automation']['port'],
            'SOCIAL_AUTOMATION_DB_DATABASE' => $databases['omi_social_automation']['database'],
            'SOCIAL_AUTOMATION_DB_USERNAME' => $databases['omi_social_automation']['username'],
            'SOCIAL_AUTOMATION_DB_PASSWORD' => $databases['omi_social_automation']['password'],
        ]);

        // 3. Update runtime connection config
        foreach ($databases as $key => $target) {
            Config::set("database.connections.{$key}.host", $target['host']);
            Config::set("database.connections.{$key}.port", $target['port']);
            Config::set("database.connections.{$key}.database", $target['database']);
            Config::set("database.connections.{$key}.username", $target['username']);
            Config::set("database.connections.{$key}.password", $target['password']);
            DB::purge($key);
        }

        // 4. Save state
        $this->stateManager->saveRecord([
            'status' => InstallationState::CONFIGURING,
            'step' => 'migrations',
            'databases' => [
                'mysql' => $databases['mysql']['database'],
                'omi_seo_ai' => $databases['omi_seo_ai']['database'],
                'omi_seeding' => $databases['omi_seeding']['database'],
                'omi_social_automation' => $databases['omi_social_automation']['database'],
            ],
            'updated_at' => date('c'),
        ]);

        return [
            'ok' => true,
            'message' => 'All database connections verified and configured successfully.',
            'details' => [
                'client' => $coreDb,
                'seo' => $seoDb,
                'seeding' => $seedingDb,
                'social_automation' => $saDb,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{ok: bool, message: string}
     */
    public function testSingleConnection(array $inputs): array
    {
        $label = trim((string) ($inputs['label'] ?? 'Database'));
        $db = trim((string) ($inputs['database'] ?? $inputs['db'] ?? ''));
        $host = trim((string) ($inputs['host'] ?? '127.0.0.1'));
        $port = (int) ($inputs['port'] ?? 3306);
        $user = trim((string) ($inputs['username'] ?? 'root'));
        $pass = (string) ($inputs['password'] ?? '');

        if ($db === '') {
            return [
                'ok' => false,
                'message' => "Database name for {$label} is required.",
            ];
        }

        return $this->verifyDatabaseTarget([
            'label' => $label,
            'database' => $db,
            'host' => $host,
            'port' => $port,
            'username' => $user,
            'password' => $pass,
        ]);
    }

    /**
     * @param  array{label: string, database: string, host: string, port: int, username: string, password: string}  $target
     * @return array{ok: bool, message: string}
     */
    private function verifyDatabaseTarget(array $target): array
    {
        $label = $target['label'];
        $db = $target['database'];
        $host = $target['host'];
        $port = $target['port'];
        $user = $target['username'];
        $pass = $target['password'];

        if (! preg_match('/^[a-zA-Z0-9_\-]+$/', $db)) {
            return [
                'ok' => false,
                'message' => "Invalid database name [{$db}] for {$label}. Use alphanumeric characters and underscores only.",
            ];
        }

        // Connect to server (without db) to verify server access
        try {
            $serverPdo = new PDO(
                "mysql:host={$host};port={$port};charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 4,
                ]
            );
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => "Could not connect to MySQL server for {$label} ({$host}:{$port}): {$e->getMessage()}",
            ];
        }

        // Try creating database if not exists
        try {
            $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            // User might not have CREATE privilege; attempt to connect directly to existing DB
        }

        // Connect directly to the specific database
        try {
            $dbPdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 4,
                ]
            );
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => "Could not connect to database [{$db}] for {$label}: {$e->getMessage()}",
            ];
        }

        // Safety check: verify database does not contain conflicting populated application tables
        $tables = $dbPdo->query("SHOW TABLES FROM `{$db}`")->fetchAll(PDO::FETCH_COLUMN);
        if (count($tables) > 0) {
            // If users table exists and has owner/admin rows, block installation to prevent accidental overwrite
            if (in_array('users', $tables, true)) {
                $userCount = (int) $dbPdo->query("SELECT COUNT(*) FROM `users` WHERE `role` IN ('owner', 'admin') AND `is_system` = 0")->fetchColumn();
                if ($userCount > 0) {
                    return [
                        'ok' => false,
                        'message' => "Database [{$db}] already contains an installed SEO-OPS database with {$userCount} administrator(s). To protect existing data, the installer will not overwrite existing databases.",
                    ];
                }
            }
        }

        // Test DDL and DML permissions with temporary table
        $permTable = '_installer_perm_check_'.bin2hex(random_bytes(4));
        try {
            $dbPdo->exec("CREATE TABLE `{$permTable}` (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(50)) ENGINE=InnoDB");
            $dbPdo->exec("INSERT INTO `{$permTable}` (name) VALUES ('test')");
            $dbPdo->query("SELECT * FROM `{$permTable}`")->fetchAll();
            $dbPdo->exec("UPDATE `{$permTable}` SET name = 'updated' WHERE id = 1");
            $dbPdo->exec("DROP TABLE `{$permTable}`");
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => "Database [{$db}] for {$label} lacks required permissions (CREATE/INSERT/UPDATE/DROP): {$e->getMessage()}",
            ];
        }

        return ['ok' => true, 'message' => 'OK'];
    }

    /**
     * @param  array<string, string>  $variables
     */
    public function updateEnvironmentVariables(array $variables): void
    {
        $envPath = base_path('.env');
        if (! is_file($envPath)) {
            $examplePath = base_path('.env.example');
            if (is_file($examplePath)) {
                copy($examplePath, $envPath);
            } else {
                touch($envPath);
            }
        }

        $contents = (string) file_get_contents($envPath);
        $lines = explode("\n", $contents);
        $keysToUpdate = array_keys($variables);
        $updatedKeys = [];

        $newLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $newLines[] = $line;
                continue;
            }

            $parts = explode('=', $line, 2);
            $key = trim($parts[0]);

            if (in_array($key, $keysToUpdate, true)) {
                $val = $variables[$key];
                $formattedVal = $this->formatEnvValue($val);
                $newLines[] = "{$key}={$formattedVal}";
                $updatedKeys[] = $key;
            } else {
                $newLines[] = $line;
            }
        }

        // Add any remaining keys that were not present in existing .env
        $missingKeys = array_diff($keysToUpdate, $updatedKeys);
        if (! empty($missingKeys)) {
            $newLines[] = '';
            $newLines[] = '# Database connections configured by installer';
            foreach ($missingKeys as $key) {
                $formattedVal = $this->formatEnvValue($variables[$key]);
                $newLines[] = "{$key}={$formattedVal}";
            }
        }

        $newContent = implode("\n", $newLines);
        $tmp = $envPath.'.tmp.'.bin2hex(random_bytes(4));
        file_put_contents($tmp, $newContent);
        rename($tmp, $envPath);
    }

    private function formatEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (str_contains($value, ' ') || str_contains($value, '#') || str_contains($value, '"') || str_contains($value, "'")) {
            return '"'.str_replace('"', '\"', $value).'"';
        }

        return $value;
    }
}

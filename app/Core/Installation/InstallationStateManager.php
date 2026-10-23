<?php

declare(strict_types=1);

namespace App\Core\Installation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use Throwable;

final class InstallationStateManager
{
    private ?string $cachedStatus = null;
    private ?string $customStateDir = null;
    private ?string $forcedStatus = null;

    public function __construct(?string $customStateDir = null)
    {
        $this->customStateDir = $customStateDir;
    }

    public function setStatusForTesting(?string $status): void
    {
        $this->forcedStatus = $status;
        $this->cachedStatus = null;
    }

    public function setStateDirectory(?string $dir): void
    {
        $this->customStateDir = $dir;
        $this->cachedStatus = null;
    }

    public function stateDirectory(): string
    {
        return $this->customStateDir ?? storage_path('installer');
    }

    public function lockFilePath(): string
    {
        return $this->stateDirectory().DIRECTORY_SEPARATOR.'installed.lock';
    }

    public function stateFilePath(): string
    {
        return $this->stateDirectory().DIRECTORY_SEPARATOR.'state.json';
    }

    public function tokenFilePath(): string
    {
        return $this->stateDirectory().DIRECTORY_SEPARATOR.'setup-token.txt';
    }

    public function processLockFilePath(): string
    {
        return $this->stateDirectory().DIRECTORY_SEPARATOR.'process.lock';
    }

    public function ensureDirectory(): void
    {
        $dir = $this->stateDirectory();
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readRecord(): ?array
    {
        $file = $this->stateFilePath();
        if (! is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveRecord(array $data): void
    {
        $this->ensureDirectory();
        $file = $this->stateFilePath();
        $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmp = $file.'.tmp.'.Str::random(8);

        file_put_contents($tmp, (string) $payload);
        rename($tmp, $file);
        $this->cachedStatus = null;
    }

    public function hasInstalledLock(): bool
    {
        return is_file($this->lockFilePath());
    }

    public function isFreshUnlicensed(): bool
    {
        if (! $this->hasInstalledLock()) {
            return false;
        }

        $record = $this->readRecord();
        if ($record === null) {
            return false;
        }

        if (($record['fresh_v1'] ?? false) !== true) {
            return false;
        }

        if (($record['h1_enrolled'] ?? false) === true) {
            return false;
        }

        try {
            if (class_exists(\App\Services\H1\Persistence\InstallationStateRepository::class)) {
                $installations = app(\App\Services\H1\Persistence\InstallationStateRepository::class);
                if ($installations->isEnrolled()) {
                    return false;
                }
            }
        } catch (\Throwable) {
        }

        return true;
    }

    public function getOrCreateSetupToken(): string
    {
        $this->ensureDirectory();
        $file = $this->tokenFilePath();
        if (is_file($file)) {
            $token = trim((string) @file_get_contents($file));
            if ($token !== '') {
                return $token;
            }
        }

        $token = Str::random(32);
        file_put_contents($file, $token);

        return $token;
    }

    public function verifySetupToken(string $token): bool
    {
        $actual = $this->getOrCreateSetupToken();

        return hash_equals($actual, trim($token));
    }

    public function currentStatus(): string
    {
        if ($this->forcedStatus !== null) {
            return $this->forcedStatus;
        }

        if ($this->cachedStatus !== null) {
            return $this->cachedStatus;
        }

        $this->cachedStatus = $this->resolveStatus();

        return $this->cachedStatus;
    }

    private function resolveStatus(): string
    {
        // 1. Explicit installed.lock = already installed. Unreachable DB = outage (503), never Setup.
        if ($this->hasInstalledLock()) {
            if (! $this->probeDatabaseReachable()) {
                return InstallationState::DATABASE_UNAVAILABLE;
            }

            return InstallationState::INSTALLED;
        }

        // 2. state.json
        $record = $this->readRecord();
        $stateStatus = strtoupper((string) ($record['status'] ?? ''));

        if ($stateStatus === InstallationState::INSTALLED) {
            if (! $this->probeDatabaseReachable()) {
                return InstallationState::DATABASE_UNAVAILABLE;
            }

            return InstallationState::INSTALLED;
        }

        // Mid-setup / recovery: Setup must stay reachable while databases are being created.
        // Do not escalate missing/unreachable DB to DATABASE_UNAVAILABLE without install evidence.
        if ($stateStatus === InstallationState::RECOVERABLE_FAILURE) {
            return InstallationState::RECOVERABLE_FAILURE;
        }

        if ($stateStatus === InstallationState::CONFIGURING) {
            return InstallationState::CONFIGURING;
        }

        if ($stateStatus === InstallationState::UNCONFIGURED) {
            return InstallationState::UNCONFIGURED;
        }

        // 3. No install evidence. .env may already name databases that do not exist yet
        // (fresh setup). Only auto-promote to INSTALLED when schema is present.
        $coreConn = (string) config('database.core_connection', config('database.default', 'mysql'));
        $connConfig = config("database.connections.{$coreConn}", []);
        $configuredDb = (string) ($connConfig['database'] ?? '');
        $hasAppKey = (string) config('app.key', '') !== '';

        if ($configuredDb !== '' && $hasAppKey && $this->probeDatabaseReachable()) {
            if ($this->databaseHasInstalledSchema()) {
                return InstallationState::INSTALLED;
            }
        }

        return InstallationState::UNCONFIGURED;
    }

    public function probeDatabaseReachable(): bool
    {
        try {
            $coreConn = (string) config('database.core_connection', config('database.default', 'mysql'));
            $connConfig = config("database.connections.{$coreConn}", []);
            $driver = (string) ($connConfig['driver'] ?? 'mysql');

            if ($driver === 'sqlite') {
                $db = (string) ($connConfig['database'] ?? '');
                if ($db === '') {
                    return false;
                }
                $dsn = "sqlite:{$db}";
                $pdo = new PDO($dsn);
                $stmt = $pdo->query('SELECT 1');

                return $stmt !== false;
            }

            $host = (string) ($connConfig['host'] ?? '127.0.0.1');
            $port = (int) ($connConfig['port'] ?? 3306);
            $db = (string) ($connConfig['database'] ?? '');
            $user = (string) ($connConfig['username'] ?? 'root');
            $pass = (string) ($connConfig['password'] ?? '');

            if ($db === '') {
                return false;
            }

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2,
            ]);

            $stmt = $pdo->query('SELECT 1');

            return $stmt !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function databaseHasInstalledSchema(): bool
    {
        try {
            $coreConn = (string) config('database.core_connection', config('database.default', 'mysql'));
            if (! Schema::connection($coreConn)->hasTable('users')) {
                return false;
            }

            return DB::connection($coreConn)->table('users')
                ->whereIn('role', ['owner', 'admin'])
                ->where('is_system', false)
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markInstalled(array $metadata = []): void
    {
        $this->ensureDirectory();

        $lockPayload = [
            'installed_at' => date('c'),
            'client_version' => '1.0.0',
            'fresh_unlicensed' => true,
        ];
        file_put_contents($this->lockFilePath(), json_encode($lockPayload, JSON_PRETTY_PRINT));

        $record = array_merge($this->readRecord() ?? [], [
            'status' => 'installed',
            'step' => 'completed',
            'installed_at' => date('c'),
            'commercial_status' => 'unlicensed',
            'h1_enrolled' => false,
            'services_activated' => false,
            'fresh_v1' => true,
        ], $metadata);

        $this->saveRecord($record);
        $this->cachedStatus = InstallationState::INSTALLED;
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function withLock(callable $callback): mixed
    {
        $this->ensureDirectory();
        $lockFile = $this->processLockFilePath();
        $fp = fopen($lockFile, 'c+');
        if (! $fp) {
            throw new \RuntimeException('Failed to acquire setup file descriptor.');
        }

        if (! flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            throw new \RuntimeException('Setup is currently locked by another operation.');
        }

        try {
            return $callback();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($lockFile);
        }
    }

    public function resetForTesting(): void
    {
        $this->cachedStatus = null;
    }
}

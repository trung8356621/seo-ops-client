<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Support;

use PDO;

final class ReferenceMap
{
    private PDO $pdo;

    private string $dbPath;

    public function __construct(
        public readonly string $importRunId,
        ?string $dbPath = null,
    ) {
        if ($dbPath === null) {
            $dir = storage_path('app/client-transfer');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $this->dbPath = $dir . "/refmap_{$importRunId}.sqlite";
        } else {
            $this->dbPath = $dbPath;
        }

        $this->initPdo();
    }

    private function initPdo(): void
    {
        $this->pdo = new PDO("sqlite:{$this->dbPath}");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA synchronous = OFF');
        $this->pdo->exec('PRAGMA journal_mode = MEMORY');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS references_map (
                source_ref TEXT PRIMARY KEY,
                entity_type TEXT NOT NULL,
                target_id INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_entity_type ON references_map (entity_type);

            CREATE TABLE IF NOT EXISTS deferred_references (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type TEXT NOT NULL,
                target_id INTEGER NOT NULL,
                field_name TEXT NOT NULL,
                target_ref TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_def_entity ON deferred_references (entity_type);
        ');
    }

    public function set(string $sourceRef, string $entityType, int $targetId): void
    {
        $stmt = $this->pdo->prepare('
            INSERT OR REPLACE INTO references_map (source_ref, entity_type, target_id)
            VALUES (:source_ref, :entity_type, :target_id)
        ');
        $stmt->execute([
            ':source_ref' => $sourceRef,
            ':entity_type' => $entityType,
            ':target_id' => $targetId,
        ]);
    }

    /**
     * @param  list<array{source_ref: string, entity_type: string, target_id: int}>  $rows
     */
    public function setMany(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('
                INSERT OR REPLACE INTO references_map (source_ref, entity_type, target_id)
                VALUES (:source_ref, :entity_type, :target_id)
            ');
            foreach ($rows as $row) {
                $stmt->execute([
                    ':source_ref' => $row['source_ref'],
                    ':entity_type' => $row['entity_type'],
                    ':target_id' => $row['target_id'],
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function get(string $sourceRef): ?int
    {
        $stmt = $this->pdo->prepare('
            SELECT target_id FROM references_map WHERE source_ref = :source_ref LIMIT 1
        ');
        $stmt->execute([':source_ref' => $sourceRef]);
        $val = $stmt->fetchColumn();

        return $val !== false ? (int) $val : null;
    }

    public function has(string $sourceRef): bool
    {
        return $this->get($sourceRef) !== null;
    }

    /**
     * @param  list<string>  $sourceRefs
     * @return array<string, int>
     */
    public function getMany(array $sourceRefs): array
    {
        if (empty($sourceRefs)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($sourceRefs), '?'));
        $stmt = $this->pdo->prepare("
            SELECT source_ref, target_id FROM references_map WHERE source_ref IN ({$placeholders})
        ");
        $stmt->execute($sourceRefs);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[(string) $row['source_ref']] = (int) $row['target_id'];
        }

        return $results;
    }

    public function addDeferred(string $entityType, int $targetId, string $fieldName, string $targetRef): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO deferred_references (entity_type, target_id, field_name, target_ref)
            VALUES (:entity_type, :target_id, :field_name, :target_ref)
        ');
        $stmt->execute([
            ':entity_type' => $entityType,
            ':target_id' => $targetId,
            ':field_name' => $fieldName,
            ':target_ref' => $targetRef,
        ]);
    }

    /**
     * @return list<array{id: int, entity_type: string, target_id: int, field_name: string, target_ref: string}>
     */
    public function getDeferredReferences(): array
    {
        $stmt = $this->pdo->query('
            SELECT id, entity_type, target_id, field_name, target_ref FROM deferred_references ORDER BY id ASC
        ');

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM references_map')->fetchColumn();
    }

    public function countDeferred(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM deferred_references')->fetchColumn();
    }

    public function cleanup(): void
    {
        unset($this->pdo);
        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function getDbPath(): string
    {
        return $this->dbPath;
    }
}

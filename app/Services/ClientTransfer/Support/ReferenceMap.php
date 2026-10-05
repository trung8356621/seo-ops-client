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

            CREATE TABLE IF NOT EXISTS failed_roots (
                root_ref TEXT PRIMARY KEY
            );

            CREATE TABLE IF NOT EXISTS transfer_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id TEXT NOT NULL,
                dataset TEXT NOT NULL,
                part TEXT NOT NULL,
                record_ref TEXT NOT NULL,
                status TEXT NOT NULL,
                error_type TEXT NOT NULL,
                message TEXT NOT NULL,
                record_index INTEGER DEFAULT 0,
                byte_offset INTEGER DEFAULT 0,
                source_file TEXT,
                blob_ref TEXT,
                created_at TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_log_run_status ON transfer_logs (run_id, status);
            CREATE INDEX IF NOT EXISTS idx_log_dataset_part ON transfer_logs (dataset, part);
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

    public function hasImported(string $sourceRef): bool
    {
        return $this->has($sourceRef);
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

    /**
     * @return list<array{id: int, entity_type: string, target_id: int, field_name: string, target_ref: string}>
     */
    public function getDeferredReferencesChunk(int $afterId = 0, int $limit = 500): array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, entity_type, target_id, field_name, target_ref 
            FROM deferred_references 
            WHERE id > :after_id 
            ORDER BY id ASC 
            LIMIT :limit
        ');
        $stmt->bindValue(':after_id', $afterId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function markFailedRoot(string $rootRef): void
    {
        $stmt = $this->pdo->prepare('
            INSERT OR IGNORE INTO failed_roots (root_ref) VALUES (:root_ref)
        ');
        $stmt->execute([':root_ref' => $rootRef]);
    }

    public function isRootFailed(string $rootRef): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT 1 FROM failed_roots WHERE root_ref = :root_ref LIMIT 1
        ');
        $stmt->execute([':root_ref' => $rootRef]);

        return $stmt->fetchColumn() !== false;
    }

    public function logRecord(
        string $runId,
        string $dataset,
        string $part,
        string $recordRef,
        string $status,
        string $errorType,
        string $message,
        int $recordIndex = 0,
        ?string $sourceFile = null,
        ?string $blobRef = null,
        int $byteOffset = 0,
    ): void {
        $stmt = $this->pdo->prepare('
            INSERT INTO transfer_logs (
                run_id, dataset, part, record_ref, status, error_type, message, record_index, byte_offset, source_file, blob_ref, created_at
            ) VALUES (
                :run_id, :dataset, :part, :record_ref, :status, :error_type, :message, :record_index, :byte_offset, :source_file, :blob_ref, :created_at
            )
        ');
        $stmt->execute([
            ':run_id' => $runId,
            ':dataset' => $dataset,
            ':part' => $part,
            ':record_ref' => $recordRef,
            ':status' => $status,
            ':error_type' => $errorType,
            ':message' => $message,
            ':record_index' => $recordIndex,
            ':byte_offset' => $byteOffset,
            ':source_file' => $sourceFile,
            ':blob_ref' => $blobRef,
            ':created_at' => date('c'),
        ]);
    }

    public function countFailures(): int
    {
        return (int) $this->pdo->query("
            SELECT COUNT(*) FROM transfer_logs WHERE status IN ('failed', 'blocked_by_parent')
        ")->fetchColumn();
    }

    public function countLogs(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM transfer_logs')->fetchColumn();
    }

    /**
     * @return list<array{
     *     id: int,
     *     run_id: string,
     *     dataset: string,
     *     part: string,
     *     record_ref: string,
     *     status: string,
     *     error_type: string,
     *     message: string,
     *     record_index: int,
     *     byte_offset: int,
     *     source_file: ?string,
     *     blob_ref: ?string,
     *     created_at: string
     * }>
     */
    public function getLogsChunk(int $afterId = 0, int $limit = 500): array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, run_id, dataset, part, record_ref, status, error_type, message, record_index, byte_offset, source_file, blob_ref, created_at 
            FROM transfer_logs 
            WHERE id > :after_id 
            ORDER BY id ASC 
            LIMIT :limit
        ');
        $stmt->bindValue(':after_id', $afterId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array{dataset: string, part: string, source_file: ?string}>
     */
    public function getFailedParts(): array
    {
        $stmt = $this->pdo->query("
            SELECT DISTINCT dataset, part, source_file 
            FROM transfer_logs 
            WHERE status IN ('failed', 'blocked_by_parent')
            ORDER BY dataset ASC, part ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, array{record_ref: string, blob_ref: ?string}>
     */
    public function getFailedRecordsForPart(string $dataset, string $part): array
    {
        $stmt = $this->pdo->prepare("
            SELECT record_ref, blob_ref 
            FROM transfer_logs 
            WHERE dataset = :dataset AND part = :part AND status IN ('failed', 'blocked_by_parent')
        ");
        $stmt->execute([
            ':dataset' => $dataset,
            ':part' => $part,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['record_ref']] = $row;
        }

        return $map;
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

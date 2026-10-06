<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Contracts\DatasetInterface;
use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\NdjsonPartWriter;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;

abstract class BaseDataset implements DatasetInterface
{
    public function maxRecordsPerPart(): int
    {
        return 20000;
    }

    public function maxBytesPerPart(): int
    {
        return 16777216; // 16 MB
    }

    public function resolveDeferred(ReferenceMap $refMap, ImportRun $run): void {}

    public function rollbackImportedRecord(string $targetKey, array $context = []): void
    {
        $model = $this->queryForExport()->getModel();
        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $model->newQuery()->withTrashed()->whereKey($targetKey)->forceDelete();

            return;
        }

        $model->newQuery()->whereKey($targetKey)->delete();
    }

    public function sliceLimit(): int
    {
        return 500;
    }

    abstract protected function queryForExport(): Builder|Relation;

    /**
     * @return array<string, mixed>|null
     */
    abstract protected function mapRecordForExport(mixed $row, BlobManager $blobs): ?array;

    /**
     * @return array{count: int, last_id: int, has_more: bool}
     */
    public function exportSlice(NdjsonPartWriter $writer, BlobManager $blobs, int $afterId = 0, int $limit = 500): array
    {
        $records = $this->fetchSliceRecords($afterId, $limit);

        $count = 0;
        $lastId = $afterId;

        foreach ($records as $row) {
            $record = $this->mapRecordForExport($row, $blobs);
            if ($record !== null) {
                $writer->writeRecord($record);
                $count++;
            }
            $lastId = $this->getRecordIdForSlice($row, $lastId);
        }

        return [
            'count' => $count,
            'last_id' => $lastId,
            'has_more' => count($records) >= $limit,
        ];
    }

    /**
     * @return iterable<mixed>
     */
    protected function fetchSliceRecords(int $afterId, int $limit): iterable
    {
        return $this->queryForExport()
            ->where('id', '>', $afterId)
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get();
    }

    protected function getRecordIdForSlice(mixed $row, int $currentLastId): int
    {
        return isset($row->id) ? (int) $row->id : $currentLastId + 1;
    }

    public function export(NdjsonPartWriter $writer, BlobManager $blobs): int
    {
        $afterId = 0;
        $total = 0;
        $limit = $this->sliceLimit();
        do {
            $slice = $this->exportSlice($writer, $blobs, $afterId, $limit);
            $total += $slice['count'];
            $afterId = $slice['last_id'];
        } while ($slice['has_more']);

        return $total;
    }

    public function exportSelectedRefs(iterable $refs, NdjsonPartWriter $writer, BlobManager $blobs): array
    {
        $idsToRefs = [];
        $compositeRefs = [];
        foreach ($refs as $ref) {
            if (preg_match('/^[a-z_]+:(\d+)$/', $ref, $matches) === 1) {
                $idsToRefs[(int) $matches[1]] = $ref;
            } elseif (preg_match('/^topic:(\d+)_topic_tag:(\d+)$/', $ref, $matches) === 1) {
                $compositeRefs[] = ['topic_id' => (int) $matches[1], 'tag_id' => (int) $matches[2], 'ref' => $ref];
            } else {
                $idsToRefs[-count($idsToRefs) - 1] = $ref;
            }
        }

        $count = 0;
        foreach (array_chunk(array_filter(array_keys($idsToRefs), static fn (int $id): bool => $id > 0), 500) as $ids) {
            foreach ($this->queryForExport()->whereKey($ids)->get() as $row) {
                $record = $this->mapRecordForExport($row, $blobs);
                if ($record !== null) {
                    $writer->writeRecord($record);
                    $count++;
                }
                unset($idsToRefs[(int) $row->getKey()]);
            }
        }

        $unresolvedComposite = [];
        foreach ($compositeRefs as $comp) {
            $row = $this->queryForExport()->where('topic_id', $comp['topic_id'])->where('tag_id', $comp['tag_id'])->first();
            if ($row !== null) {
                $record = $this->mapRecordForExport($row, $blobs);
                if ($record !== null) {
                    $writer->writeRecord($record);
                    $count++;

                    continue;
                }
            }
            $unresolvedComposite[] = $comp['ref'];
        }

        return ['count' => $count, 'unresolved' => array_values(array_merge($idsToRefs, $unresolvedComposite))];
    }
}

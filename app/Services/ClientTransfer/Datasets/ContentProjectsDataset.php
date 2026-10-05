<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\NdjsonPartWriter;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;

final class ContentProjectsDataset extends BaseDataset
{
    public function key(): string
    {
        return 'content_projects';
    }

    public function relativeSubdir(): string
    {
        return 'projects/content_projects';
    }

    public function dependencies(): array
    {
        return ['sites', 'users'];
    }

    public function export(NdjsonPartWriter $writer, BlobManager $blobs): int
    {
        $count = 0;
        SeoProject::query()->orderBy('id')->chunkById(500, function ($rows) use ($writer, &$count): void {
            foreach ($rows as $row) {
                $record = [
                    'ref' => 'project:' . $row->id,
                    'site_ref' => 'site:' . $row->site_id,
                    'user_ref' => $row->user_id ? ('user:' . $row->user_id) : null,
                    'source_draft_project_ref' => $row->source_draft_project_id ? ('project:' . $row->source_draft_project_id) : null,
                    'name' => (string) $row->name,
                    'month' => $row->month ? (is_string($row->month) ? $row->month : $row->month->format('Y-m-d')) : null,
                    'status' => (string) ($row->status ?? SeoProject::STATUS_DRAFT),
                    'kind' => (string) ($row->kind ?? SeoProject::KIND_MONTHLY),
                    'total_tasks' => (int) ($row->total_tasks ?? 0),
                    'meta' => $row->meta,
                    'archived_at' => $row->archived_at?->toIso8601String(),
                    'archived_by_ref' => $row->archived_by ? ('user:' . $row->archived_by) : null,
                    'created_at' => $row->created_at?->toIso8601String(),
                    'updated_at' => $row->updated_at?->toIso8601String(),
                ];

                $writer->writeRecord($record);
                $count++;
            }
        });

        return $count;
    }

    public function importRecord(
        array $record,
        ReferenceMap $refMap,
        ImportRun $run,
        BlobManager $blobs,
        string $partFile,
        int $recordIndex,
    ): void {
        $ref = (string) ($record['ref'] ?? '');
        $siteRef = (string) ($record['site_ref'] ?? '');
        $userRef = (string) ($record['user_ref'] ?? '');

        $siteId = $refMap->get($siteRef);
        if ($siteId === null) {
            $run->recordWarning('content_projects', $ref, "Missing dependency site [{$siteRef}]", $partFile, $recordIndex, isMissingRef: true);
            return;
        }

        $userId = $userRef !== '' ? $refMap->get($userRef) : null;
        $archivedBy = ! empty($record['archived_by_ref']) ? $refMap->get((string) $record['archived_by_ref']) : null;

        try {
            $project = new SeoProject();
            $project->site_id = $siteId;
            $project->user_id = $userId;
            $project->name = (string) ($record['name'] ?? 'Imported Project');
            $project->month = $record['month'] ?? null;
            $project->status = (string) ($record['status'] ?? SeoProject::STATUS_DRAFT);
            $project->kind = (string) ($record['kind'] ?? SeoProject::KIND_MONTHLY);
            $project->total_tasks = (int) ($record['total_tasks'] ?? 0);
            $project->meta = $record['meta'] ?? null;
            if (! empty($record['archived_at'])) {
                $project->archived_at = $record['archived_at'];
            }
            $project->archived_by = $archivedBy;
            if (! empty($record['created_at'])) {
                $project->created_at = $record['created_at'];
            }
            if (! empty($record['updated_at'])) {
                $project->updated_at = $record['updated_at'];
            }
            $project->save();

            $targetProjectId = (int) $project->id;
            $refMap->set($ref, 'project', $targetProjectId);

            if (! empty($record['source_draft_project_ref'])) {
                $refMap->addDeferred('seo_projects', $targetProjectId, 'source_draft_project_id', (string) $record['source_draft_project_ref']);
            }

            $run->recordImported('content_projects', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('content_projects', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }

    public function resolveDeferred(ReferenceMap $refMap, ImportRun $run): void
    {
        $deferred = $refMap->getDeferredReferences();
        foreach ($deferred as $item) {
            if ($item['entity_type'] !== 'seo_projects' || $item['field_name'] !== 'source_draft_project_id') {
                continue;
            }

            $targetDraftId = $refMap->get($item['target_ref']);
            if ($targetDraftId !== null && $targetDraftId > 0) {
                SeoProject::query()->where('id', $item['target_id'])->update(['source_draft_project_id' => $targetDraftId]);
            } else {
                $run->recordWarning('content_projects', 'project:' . $item['target_id'], "Unresolved deferred source draft project ref [{$item['target_ref']}]", isMissingRef: true);
            }
        }
    }
}

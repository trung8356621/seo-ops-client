<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

final class ContentProjectTasksDataset extends BaseDataset
{
    public function key(): string
    {
        return 'content_project_tasks';
    }

    public function relativeSubdir(): string
    {
        return 'projects/content_project_tasks';
    }

    public function dependencies(): array
    {
        return ['content_projects', 'sites', 'articles', 'users'];
    }

    protected function queryForExport(): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return SeoProjectTask::withTrashed();
    }

    protected function mapRecordForExport(mixed $row, BlobManager $blobs): ?array
    {
        return [
            'ref' => 'project_task:'.$row->id,
            'project_ref' => 'project:'.$row->project_id,
            'site_ref' => 'site:'.$row->site_id,
            'article_ref' => $row->article_id ? ('article:'.$row->article_id) : null,
            'archived_from_project_ref' => $row->archived_from_project_id ? ('project:'.$row->archived_from_project_id) : null,
            'keyword' => (string) ($row->keyword ?? ''),
            'title' => (string) ($row->title ?? ''),
            'type' => (string) ($row->type ?? SeoProjectTask::TYPE_CREATE),
            'status' => (string) ($row->status ?? SeoProjectTask::STATUS_PENDING),
            'target_date' => $row->target_date ? (is_string($row->target_date) ? $row->target_date : $row->target_date->format('Y-m-d')) : null,
            'scheduled_publish_at' => $row->scheduled_publish_at?->toIso8601String(),
            'content_manager_reviewed_at' => $row->content_manager_reviewed_at?->toIso8601String(),
            'content_manager_reviewed_by_ref' => $row->content_manager_reviewed_by ? ('user:'.$row->content_manager_reviewed_by) : null,
            'planning_reviewed_at' => $row->planning_reviewed_at?->toIso8601String(),
            'planning_reviewed_by_ref' => $row->planning_reviewed_by ? ('user:'.$row->planning_reviewed_by) : null,
            'publishing_queued_at' => $row->publishing_queued_at?->toIso8601String(),
            'publishing_queued_by_ref' => $row->publishing_queued_by ? ('user:'.$row->publishing_queued_by) : null,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
            'deleted_at' => $row->deleted_at?->toIso8601String(),
        ];
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
        $projectRef = (string) ($record['project_ref'] ?? '');
        $siteRef = (string) ($record['site_ref'] ?? '');

        $projectId = $refMap->get($projectRef);
        $siteId = $refMap->get($siteRef);

        if ($projectId === null || $siteId === null) {
            $run->recordWarning('content_project_tasks', $ref, "Missing dependency project [{$projectRef}] or site [{$siteRef}]", $partFile, $recordIndex, isMissingRef: true);

            return;
        }

        $articleId = ! empty($record['article_ref']) ? $refMap->get((string) $record['article_ref']) : null;
        $cmReviewedBy = ! empty($record['content_manager_reviewed_by_ref']) ? $refMap->get((string) $record['content_manager_reviewed_by_ref']) : null;
        $planReviewedBy = ! empty($record['planning_reviewed_by_ref']) ? $refMap->get((string) $record['planning_reviewed_by_ref']) : null;
        $pubQueuedBy = ! empty($record['publishing_queued_by_ref']) ? $refMap->get((string) $record['publishing_queued_by_ref']) : null;

        try {
            $task = new SeoProjectTask;
            $task->project_id = $projectId;
            $task->site_id = $siteId;
            $task->article_id = $articleId;
            $task->keyword = (string) ($record['keyword'] ?? '');
            $task->title = (string) ($record['title'] ?? '');
            $task->type = (string) ($record['type'] ?? SeoProjectTask::TYPE_CREATE);
            $task->status = (string) ($record['status'] ?? SeoProjectTask::STATUS_PENDING);
            $task->target_date = $record['target_date'] ?? null;
            if (! empty($record['scheduled_publish_at'])) {
                $task->scheduled_publish_at = $record['scheduled_publish_at'];
            }
            if (! empty($record['content_manager_reviewed_at'])) {
                $task->content_manager_reviewed_at = $record['content_manager_reviewed_at'];
            }
            $task->content_manager_reviewed_by = $cmReviewedBy;
            if (! empty($record['planning_reviewed_at'])) {
                $task->planning_reviewed_at = $record['planning_reviewed_at'];
            }
            $task->planning_reviewed_by = $planReviewedBy;
            if (! empty($record['publishing_queued_at'])) {
                $task->publishing_queued_at = $record['publishing_queued_at'];
            }
            $task->publishing_queued_by = $pubQueuedBy;
            if (! empty($record['created_at'])) {
                $task->created_at = $record['created_at'];
            }
            if (! empty($record['updated_at'])) {
                $task->updated_at = $record['updated_at'];
            }
            if (! empty($record['deleted_at'])) {
                $task->deleted_at = $record['deleted_at'];
            }
            $task->save();
            $refMap->trackCreated($this->key(), (int) $task->id);

            $targetTaskId = (int) $task->id;
            $refMap->set($ref, 'project_task', $targetTaskId);

            if (! empty($record['archived_from_project_ref'])) {
                $refMap->addDeferred('seo_project_tasks', $targetTaskId, 'archived_from_project_id', (string) $record['archived_from_project_ref']);
            }

            $run->recordImported('content_project_tasks', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->recordFailed('content_project_tasks', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }

    public function resolveDeferred(ReferenceMap $refMap, ImportRun $run): void
    {
        $deferred = $refMap->getDeferredReferences();
        foreach ($deferred as $item) {
            if ($item['entity_type'] !== 'seo_project_tasks' || $item['field_name'] !== 'archived_from_project_id') {
                continue;
            }

            $targetProjectId = $refMap->get($item['target_ref']);
            if ($targetProjectId !== null && $targetProjectId > 0) {
                SeoProjectTask::query()->where('id', $item['target_id'])->update(['archived_from_project_id' => $targetProjectId]);
            } else {
                $run->recordWarning('content_project_tasks', 'project_task:'.$item['target_id'], "Unresolved deferred archived from project ref [{$item['target_ref']}]", isMissingRef: true);
            }
        }
    }
}

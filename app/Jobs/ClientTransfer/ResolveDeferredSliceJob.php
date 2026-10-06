<?php

declare(strict_types=1);

namespace App\Jobs\ClientTransfer;

use App\Models\ClientTransferRun;
use App\Models\User;
use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Omnichannel\Addons\Content\Models\SeoArticleHeading;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;

final class ResolveDeferredSliceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $runId,
        public readonly int $afterId = 0,
    ) {
        $this->onQueue('client-transfer');
    }

    public function handle(): void
    {
        $run = ClientTransferRun::query()->where('run_id', $this->runId)->first();
        if ($run === null || $run->shouldStopTransfer()) {
            return;
        }

        $run->update([
            'phase' => 'resolve_deferred',
        ]);

        $refMap = new ReferenceMap($this->runId);
        $importRun = new ImportRun($this->runId, $refMap);

        $chunk = $refMap->getDeferredReferencesChunk($this->afterId, limit: 500);

        if (empty($chunk)) {
            $run->refresh();
            if ($run->shouldStopTransfer()) {
                return;
            }

            ValidateSeoImportJob::dispatch($this->runId)->onQueue('client-transfer');

            return;
        }

        $lastId = $this->afterId;

        foreach ($chunk as $item) {
            $lastId = (int) $item['id'];
            $entityType = (string) $item['entity_type'];
            $targetId = (int) $item['target_id'];
            $fieldName = (string) $item['field_name'];
            $targetRef = (string) $item['target_ref'];

            $resolvedId = $refMap->get($targetRef);

            if ($resolvedId !== null && $resolvedId > 0) {
                $this->applyResolvedRef($entityType, $targetId, $fieldName, $resolvedId);
            } else {
                $importRun->recordWarning(
                    dataset: $this->datasetForEntity($entityType),
                    ref: "{$entityType}:{$targetId}",
                    message: "Unresolved deferred reference [{$targetRef}] on [{$entityType}.{$fieldName}]",
                    isMissingRef: true,
                );
            }
        }

        $run->refresh();
        if ($run->shouldStopTransfer()) {
            return;
        }

        if (count($chunk) === 500) {
            ResolveDeferredSliceJob::dispatch($this->runId, $lastId)->onQueue('client-transfer');
        } else {
            ValidateSeoImportJob::dispatch($this->runId)->onQueue('client-transfer');
        }
    }

    private function applyResolvedRef(string $entityType, int $targetId, string $fieldName, int $resolvedId): void
    {
        switch ($entityType) {
            case 'users':
                User::query()->where('id', $targetId)->update([$fieldName => $resolvedId]);
                break;

            case 'article_headings':
                SeoArticleHeading::query()->where('id', $targetId)->update([$fieldName => $resolvedId]);
                break;

            case 'seo_projects':
                SeoProject::query()->where('id', $targetId)->update([$fieldName => $resolvedId]);
                break;

            case 'seo_project_tasks':
                SeoProjectTask::query()->where('id', $targetId)->update([$fieldName => $resolvedId]);
                break;

            case 'link_maps':
                SeoLinkMap::query()->where('id', $targetId)->update([$fieldName => $resolvedId]);
                break;

            case 'keyword_meta_site_article':
            case 'keyword_meta_global_article':
                try {
                    $repo = app(KeywordMetaRepository::class);
                    $repo->set($targetId, $fieldName, (string) $resolvedId);
                } catch (\Throwable) {
                    // Ignore if repo not resolvable
                }
                break;
        }
    }

    private function datasetForEntity(string $entityType): string
    {
        return match ($entityType) {
            'users' => 'users',
            'article_headings' => 'article_headings',
            'seo_projects' => 'content_projects',
            'seo_project_tasks' => 'content_project_tasks',
            'link_maps' => 'link_maps',
            'keyword_meta_site_article', 'keyword_meta_global_article' => 'keywords',
            default => 'unknown',
        };
    }

    public function failed(\Throwable $e): void
    {
        $run = ClientTransferRun::query()->where('run_id', $this->runId)->first();
        if ($run !== null && ! $run->isCancelled()) {
            $run->markFailed($e->getMessage());
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer;

use App\Services\ClientTransfer\Contracts\DatasetInterface;
use App\Services\ClientTransfer\Datasets\ArticleFaqsDataset;
use App\Services\ClientTransfer\Datasets\ArticleHeadingsDataset;
use App\Services\ClientTransfer\Datasets\ArticleMetaDataset;
use App\Services\ClientTransfer\Datasets\ArticleReviewsDataset;
use App\Services\ClientTransfer\Datasets\ArticlesDataset;
use App\Services\ClientTransfer\Datasets\ContentProjectsDataset;
use App\Services\ClientTransfer\Datasets\ContentProjectTasksDataset;
use App\Services\ClientTransfer\Datasets\KeywordsDataset;
use App\Services\ClientTransfer\Datasets\LinkExclusionsDataset;
use App\Services\ClientTransfer\Datasets\LinkMapsDataset;
use App\Services\ClientTransfer\Datasets\ManualLinksDataset;
use App\Services\ClientTransfer\Datasets\MediaDataset;
use App\Services\ClientTransfer\Datasets\PublishingArticleStatesDataset;
use App\Services\ClientTransfer\Datasets\SiteKeywordsDataset;
use App\Services\ClientTransfer\Datasets\SitesDataset;
use App\Services\ClientTransfer\Datasets\SiteServicesDataset;
use App\Services\ClientTransfer\Datasets\TopicKeywordDnaDataset;
use App\Services\ClientTransfer\Datasets\TopicKeywordsDataset;
use App\Services\ClientTransfer\Datasets\TopicsDataset;
use App\Services\ClientTransfer\Datasets\TopicTagAssignmentsDataset;
use App\Services\ClientTransfer\Datasets\TopicTagsDataset;
use App\Services\ClientTransfer\Datasets\UsersDataset;
use App\Services\ClientTransfer\Datasets\WordpressArticleLinksDataset;
use App\Services\ClientTransfer\Support\TopologicalSorter;

final class DatasetRegistry
{
    /** @var array<string, DatasetInterface> */
    private array $datasets = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    public function register(DatasetInterface $dataset): void
    {
        $this->datasets[$dataset->key()] = $dataset;
    }

    public function get(string $key): ?DatasetInterface
    {
        return $this->datasets[$key] ?? null;
    }

    /**
     * @return array<string, DatasetInterface>
     */
    public function all(): array
    {
        return $this->datasets;
    }

    /**
     * Returns all registered datasets topologically sorted by dependency order.
     *
     * @return list<DatasetInterface>
     */
    public function sortedDatasets(): array
    {
        $graph = [];
        foreach ($this->datasets as $key => $dataset) {
            $graph[$key] = $dataset->dependencies();
        }

        $sortedKeys = TopologicalSorter::sort($graph);

        $result = [];
        foreach ($sortedKeys as $key) {
            if (isset($this->datasets[$key])) {
                $result[] = $this->datasets[$key];
            }
        }

        return $result;
    }

    private function registerDefaults(): void
    {
        // Core roots
        $this->register(new UsersDataset);
        $this->register(new SitesDataset);
        $this->register(new SiteServicesDataset);

        // Search foundation / Intelligence
        $this->register(new KeywordsDataset);
        $this->register(new SiteKeywordsDataset);
        $this->register(new TopicsDataset);
        $this->register(new TopicTagsDataset);
        $this->register(new TopicKeywordsDataset);
        $this->register(new TopicTagAssignmentsDataset);
        $this->register(new TopicKeywordDnaDataset);

        // Articles & Content
        $this->register(new ArticlesDataset);
        $this->register(new ArticleMetaDataset);
        $this->register(new ArticleHeadingsDataset);
        $this->register(new ArticleFaqsDataset);
        $this->register(new ArticleReviewsDataset);

        // Links
        $this->register(new ManualLinksDataset);
        $this->register(new LinkExclusionsDataset);
        $this->register(new LinkMapsDataset);

        // Projects
        $this->register(new ContentProjectsDataset);
        $this->register(new ContentProjectTasksDataset);

        // WordPress & Publishing
        $this->register(new WordpressArticleLinksDataset);
        $this->register(new PublishingArticleStatesDataset);

        // Media
        $this->register(new MediaDataset);
    }
}

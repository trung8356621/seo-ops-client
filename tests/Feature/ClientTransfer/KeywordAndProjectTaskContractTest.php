<?php

declare(strict_types=1);

namespace Tests\Feature\ClientTransfer;

use App\Models\Site;
use App\Models\User;
use App\Services\ClientTransfer\ClientTransferExporter;
use App\Services\ClientTransfer\ClientTransferImporter;
use App\Services\ClientTransfer\Datasets\ContentProjectTasksDataset;
use App\Services\ClientTransfer\Datasets\KeywordsDataset;
use App\Services\ClientTransfer\Datasets\SiteKeywordsDataset;
use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSiteKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Tests\Unit\ClientTransfer\TransferDatabaseTestCase;

final class KeywordAndProjectTaskContractTest extends TransferDatabaseTestCase
{
    // ---------------------------------------------------------------
    // content_project_tasks.source_content
    // ---------------------------------------------------------------

    public function test_project_task_source_content_round_trips_verbatim(): void
    {
        [$site, $project, $article] = $this->seedSiteProjectArticle('Tiêu đề bài hiện tại');

        // Stored value intentionally differs from what derivation would produce.
        SeoProjectTask::query()->create([
            'project_id' => $project->id,
            'site_id' => $site->id,
            'article_id' => $article->id,
            'keyword' => '',
            'title' => '',
            'source_content' => 'Tiêu đề gốc lúc lập kế hoạch',
            'type' => SeoProjectTask::TYPE_REWRITE,
            'status' => SeoProjectTask::STATUS_PENDING,
        ]);
        SeoProjectTask::query()->create([
            'project_id' => $project->id,
            'site_id' => $site->id,
            'keyword' => 'kem dưỡng ẩm',
            'title' => 'Top kem dưỡng ẩm',
            'source_content' => 'kem dưỡng ẩm',
            'type' => SeoProjectTask::TYPE_CREATE,
            'status' => SeoProjectTask::STATUS_PENDING,
        ]);

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'tasks.zip';
        (new ClientTransferExporter)->export($zipPath);
        $this->wipeBusinessTables();

        $result = (new ClientTransferImporter)->import($zipPath);

        self::assertSame(0, $result['total_failed']);
        self::assertSame(0, $result['total_blocked']);
        self::assertSame(2, SeoProjectTask::query()->count());

        $rewrite = SeoProjectTask::query()->where('type', SeoProjectTask::TYPE_REWRITE)->firstOrFail();
        self::assertSame('Tiêu đề gốc lúc lập kế hoạch', $rewrite->source_content);
        self::assertSame(SeoArticle::query()->where('title', 'Tiêu đề bài hiện tại')->value('id'), $rewrite->article_id);

        $create = SeoProjectTask::query()->where('type', SeoProjectTask::TYPE_CREATE)->firstOrFail();
        self::assertSame('kem dưỡng ẩm', $create->source_content);
    }

    public function test_legacy_package_without_source_content_derives_canonical_value(): void
    {
        [$site, $project, $article] = $this->seedSiteProjectArticle('Bài gốc cần viết lại');
        [$refMap, $run, $blobs] = $this->importContext();
        $refMap->set('site:1', 'site', (int) $site->id);
        $refMap->set('project:1', 'project', (int) $project->id);
        $refMap->set('article:1', 'article', (int) $article->id);

        $records = [
            'project_task:1' => ['type' => 'create', 'keyword' => '  kw chính  ', 'title' => 'Tiêu đề'],
            'project_task:2' => ['type' => 'create', 'keyword' => '', 'title' => 'Chỉ có tiêu đề'],
            'project_task:3' => ['type' => 'new_keyword', 'keyword' => 'legacy kw', 'title' => ''],
            'project_task:4' => ['type' => 'rewrite', 'keyword' => 'bỏ qua', 'title' => 'bỏ qua', 'article_ref' => 'article:1'],
            'project_task:5' => ['type' => 'improve', 'keyword' => 'kw', 'title' => 't', 'source_content' => null],
        ];

        $dataset = new ContentProjectTasksDataset;
        $i = 0;
        foreach ($records as $ref => $fields) {
            $dataset->importRecord(
                array_merge(['ref' => $ref, 'project_ref' => 'project:1', 'site_ref' => 'site:1', 'status' => 'pending'], $fields),
                $refMap, $run, $blobs, 'part-0001.ndjson', $i++,
            );
        }

        self::assertSame(0, $run->totalFailed());
        self::assertSame(5, $run->totalImported());

        $expected = [
            'project_task:1' => 'kw chính',
            'project_task:2' => 'Chỉ có tiêu đề',
            'project_task:3' => 'legacy kw',
            'project_task:4' => 'Bài gốc cần viết lại',
            'project_task:5' => '',
        ];
        foreach ($expected as $ref => $value) {
            $task = SeoProjectTask::query()->findOrFail($refMap->get($ref));
            self::assertSame($value, $task->source_content, $ref);
            self::assertSame(
                SeoProjectTask::deriveSourceContent(
                    (string) $records[$ref]['type'],
                    $records[$ref]['keyword'],
                    $records[$ref]['title'],
                    $task->article_id ? (string) SeoArticle::query()->whereKey($task->article_id)->value('title') : null,
                ),
                $task->source_content,
                $ref,
            );
        }
    }

    // ---------------------------------------------------------------
    // keywords canonical identity
    // ---------------------------------------------------------------

    public function test_keywords_sharing_canonical_phrase_reuse_one_target_row(): void
    {
        $site = $this->seedSite();
        [$refMap, $run, $blobs] = $this->importContext();
        $refMap->set('site:9', 'site', (int) $site->id);

        $dataset = new KeywordsDataset;
        $dataset->importRecord([
            'ref' => 'keyword:11',
            'phrase' => 'kem chống nắng',
            'type' => 'normal',
            'source' => 'manual',
            'source_locked' => true,
            'review_status' => 'danger',
            'review_note' => 'giữ nguyên',
            'site_states' => [['site_ref' => 'site:9', 'target_url' => 'https://a.test/first', 'search_volume' => null]],
            'tags' => ['Skincare'],
            'main_article_ref' => 'article:1',
        ], $refMap, $run, $blobs, 'part-0001.ndjson', 0);

        // Legacy source row: differs raw, identical after Keyword::preparePhraseForStorage().
        $dataset->importRecord([
            'ref' => 'keyword:22',
            'phrase' => 'kem  chống nắng, kem chống nắng giá rẻ',
            'type' => 'suggest',
            'source' => 'provider',
            'source_locked' => false,
            'review_status' => 'active',
            'review_note' => null,
            'site_states' => [[
                'site_ref' => 'site:9',
                'target_url' => 'https://a.test/second',
                'search_volume' => 900,
                'difficulty' => 12.5,
                'main_article_ref' => 'article:2',
            ]],
            'tags' => ['Skincare', 'Summer'],
            'main_article_ref' => 'article:2',
            'seo_hidden' => true,
        ], $refMap, $run, $blobs, 'part-0001.ndjson', 1);

        self::assertSame(0, $run->totalFailed());
        self::assertSame(2, $run->totalImported());
        self::assertSame(1, Keyword::query()->count(), 'Canonical collision must not create a duplicate row.');

        $kw = Keyword::query()->sole();
        self::assertSame('kem chống nắng', $kw->phrase);
        self::assertSame((int) $kw->id, $refMap->get('keyword:11'));
        self::assertSame((int) $kw->id, $refMap->get('keyword:22'));

        // Target core review/source state is not overwritten by the second ref.
        self::assertSame('danger', $kw->review_status);
        self::assertTrue($kw->source_locked);
        self::assertSame('manual', $kw->source);
        self::assertSame('normal', $kw->type);
        self::assertSame('giữ nguyên', $kw->review_note);

        // Portable metadata: existing values kept, missing ones filled, tags unioned.
        $repo = app(KeywordMetaRepository::class);
        $siteId = (int) $site->id;
        self::assertSame('https://a.test/first', $repo->getSiteTargetUrl((int) $kw->id, $siteId));
        self::assertSame(900, $repo->getSiteSearchVolume((int) $kw->id, $siteId));
        self::assertSame(12.5, $repo->getSiteDifficulty((int) $kw->id, $siteId));
        self::assertSame(['Skincare', 'Summer'], json_decode((string) $repo->get((int) $kw->id, KeywordMetaKey::Tags->value), true));
        self::assertSame('1', $repo->get((int) $kw->id, KeywordMetaKey::SeoHidden->value));

        // Created exactly once in the rollback journal.
        self::assertCount(1, $refMap->getCreatedRecordsChunk('keywords'));

        // Deferred focus refs: first writer wins per meta key, no duplicate global/site entries.
        $deferred = array_values(array_filter(
            $refMap->getDeferredReferences(),
            static fn (array $d): bool => str_starts_with((string) $d['entity_type'], 'keyword_meta_'),
        ));
        self::assertCount(2, $deferred);
        $byField = array_column($deferred, 'target_ref', 'field_name');
        self::assertSame('article:1', $byField[KeywordMetaKey::MainArticleId->value]);
        self::assertSame('article:2', $byField[KeywordMetaKey::siteMainArticleId($siteId)]);

        // Deferred resolution still lands on the shared target keyword.
        $a1 = $this->seedArticle($siteId, 'Focus 1');
        $a2 = $this->seedArticle($siteId, 'Focus 2');
        $refMap->set('article:1', 'article', (int) $a1->id);
        $refMap->set('article:2', 'article', (int) $a2->id);
        $dataset->resolveDeferred($refMap, $run);
        self::assertSame((string) $a1->id, $repo->get((int) $kw->id, KeywordMetaKey::MainArticleId->value));
        self::assertSame((string) $a2->id, $repo->get((int) $kw->id, KeywordMetaKey::siteMainArticleId($siteId)));

        // Dependent dataset referencing the second logical ref resolves to the shared row.
        (new SiteKeywordsDataset)->importRecord([
            'ref' => 'site_keyword:5',
            'site_ref' => 'site:9',
            'keyword_ref' => 'keyword:22',
            'is_seo_keyword' => true,
        ], $refMap, $run, $blobs, 'part-0001.ndjson', 0);
        self::assertSame((int) $kw->id, (int) SeoSiteKeyword::query()->sole()->keyword_id);
        self::assertSame(0, $run->totalFailed());
    }

    public function test_pre_existing_target_keyword_is_reused_without_journal_or_state_override(): void
    {
        $site = $this->seedSite();
        $existing = Keyword::query()->create([
            'phrase' => 'serum vitamin c',
            'type' => 'normal',
            'source' => 'manual',
            'source_locked' => true,
            'review_status' => 'warning',
        ]);
        $repo = app(KeywordMetaRepository::class);
        $repo->setSiteTargetUrl((int) $existing->id, (int) $site->id, 'https://keep.test');

        [$refMap, $run, $blobs] = $this->importContext();
        $refMap->set('site:3', 'site', (int) $site->id);

        (new KeywordsDataset)->importRecord([
            'ref' => 'keyword:77',
            'phrase' => 'serum vitamin c, serum',
            'source' => 'provider',
            'source_locked' => false,
            'review_status' => 'active',
            'site_states' => [['site_ref' => 'site:3', 'target_url' => 'https://overwrite.test', 'search_volume' => 50]],
        ], $refMap, $run, $blobs, 'part-0001.ndjson', 0);

        self::assertSame(0, $run->totalFailed());
        self::assertSame(1, Keyword::query()->count());
        self::assertSame((int) $existing->id, $refMap->get('keyword:77'));
        self::assertSame([], $refMap->getCreatedRecordsChunk('keywords'), 'Reused target row must never be rolled back.');

        $existing->refresh();
        self::assertSame('warning', $existing->review_status);
        self::assertTrue($existing->source_locked);
        self::assertSame('manual', $existing->source);
        self::assertSame('https://keep.test', $repo->getSiteTargetUrl((int) $existing->id, (int) $site->id));
        self::assertSame(50, $repo->getSiteSearchVolume((int) $existing->id, (int) $site->id));
    }

    public function test_full_round_trip_with_legacy_duplicate_keyword_phrases(): void
    {
        $site = $this->seedSite();

        // Legacy rows predating phrase normalization: distinct raw, same canonical phrase.
        $now = now()->toDateTimeString();
        $kw1 = (int) DB::table('keywords')->insertGetId(['phrase' => 'kem chống nắng', 'type' => 'normal', 'review_status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $kw2 = (int) DB::table('keywords')->insertGetId(['phrase' => 'kem chống nắng, kem chống nắng giá rẻ', 'type' => 'normal', 'review_status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        app(KeywordMetaRepository::class)->setSiteSearchVolume($kw2, (int) $site->id, 321);

        $topic = SeoTopic::query()->create(['site_id' => $site->id, 'name' => 'Chống nắng', 'source' => 'manual', 'status' => 'active']);
        SeoTopicKeyword::query()->create(['site_id' => $site->id, 'topic_id' => $topic->id, 'keyword_id' => $kw1, 'is_seed' => true]);
        SeoSiteKeyword::query()->create(['site_id' => $site->id, 'keyword_id' => $kw2, 'phrase_kind' => 'informational', 'is_seo_keyword' => true]);

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'keywords.zip';
        (new ClientTransferExporter)->export($zipPath);
        $this->wipeBusinessTables();

        $result = (new ClientTransferImporter)->import($zipPath);

        self::assertSame(0, $result['total_failed']);
        self::assertSame(0, $result['total_blocked']);
        self::assertSame(1, Keyword::query()->count());

        $kw = Keyword::query()->sole();
        $newSite = Site::query()->where('domain', 'contract.test')->firstOrFail();
        self::assertSame('kem chống nắng', $kw->phrase);
        self::assertSame((int) $kw->id, (int) SeoTopicKeyword::query()->sole()->keyword_id);
        self::assertSame((int) $kw->id, (int) SeoSiteKeyword::query()->sole()->keyword_id);
        self::assertSame(321, app(KeywordMetaRepository::class)->getSiteSearchVolume((int) $kw->id, (int) $newSite->id));
    }

    // ---------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------

    private function seedSite(): Site
    {
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@contract.test', 'role' => 'owner']);

        return Site::query()->create(['domain' => 'contract.test', 'user_id' => $owner->id, 'status' => 'active']);
    }

    private function seedArticle(int $siteId, string $title): SeoArticle
    {
        $article = new SeoArticle;
        $article->site_id = $siteId;
        $article->title = $title;
        $article->slug = 'a-'.bin2hex(random_bytes(4));
        $article->status = 'published';
        $article->saveQuietly();

        return $article;
    }

    /**
     * @return array{0: Site, 1: SeoProject, 2: SeoArticle}
     */
    private function seedSiteProjectArticle(string $articleTitle): array
    {
        $site = $this->seedSite();
        $article = $this->seedArticle((int) $site->id, $articleTitle);
        $project = SeoProject::query()->create([
            'site_id' => $site->id,
            'name' => 'Kế hoạch',
            'status' => 'approved',
            'kind' => 'monthly',
        ]);

        return [$site, $project, $article];
    }

    /**
     * @return array{0: ReferenceMap, 1: ImportRun, 2: BlobManager}
     */
    private function importContext(): array
    {
        $runId = 'contract-'.bin2hex(random_bytes(4));
        $refMap = new ReferenceMap($runId, $this->tempDir.DIRECTORY_SEPARATOR.$runId.'.sqlite');

        return [$refMap, new ImportRun($runId, $refMap), new BlobManager($this->tempDir.DIRECTORY_SEPARATOR.'blobs')];
    }
}

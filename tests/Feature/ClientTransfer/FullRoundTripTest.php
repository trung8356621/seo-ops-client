<?php

declare(strict_types=1);

namespace Tests\Feature\ClientTransfer;

use App\Models\Service;
use App\Models\Site;
use App\Models\SiteService;
use App\Models\User;
use App\Services\ClientTransfer\ClientTransferExporter;
use App\Services\ClientTransfer\ClientTransferImporter;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleHeading;
use Omnichannel\Addons\Content\Models\SeoArticleReview;
use Omnichannel\Addons\Content\Models\SeoFaq;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Omnichannel\Addons\Publishing\Models\PublishingArticleState;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSiteKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicKeywordDna;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTag;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTagAssignment;
use Omnichannel\Addons\Seo\Models\SeoArticleProfile;
use Omnichannel\Addons\SiteSync\Models\SeoSiteLinkExclusion;
use Omnichannel\Addons\SiteSync\Models\SeoSiteManualLink;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;
use Tests\Unit\ClientTransfer\TransferDatabaseTestCase;

final class FullRoundTripTest extends TransferDatabaseTestCase
{
    public function test_full_round_trip_across_all_portable_domains(): void
    {
        // 1. Create Core Service & Users
        $owner = User::query()->create([
            'name' => 'Store Owner',
            'email' => 'owner@omni.test',
            'role' => 'owner',
        ]);

        $staff = User::query()->create([
            'name' => 'SEO Staff',
            'email' => 'staff@omni.test',
            'role' => 'staff',
            'parent_id' => $owner->id,
        ]);

        $service = Service::query()->create([
            'name' => 'SEO',
            'slug' => 'seo',
            'is_active' => true,
        ]);

        // 2. Create Site & SiteService
        $site = Site::query()->create([
            'domain' => 'client-shop.test',
            'user_id' => $owner->id,
            'status' => 'active',
            'ssl' => true,
        ]);

        SiteService::query()->create([
            'site_id' => $site->id,
            'user_id' => $owner->id,
            'service_id' => $service->id,
            'bound_type' => 'site',
            'status' => 'active',
            'settings' => ['language' => 'vi', 'target_locale' => 'vn'],
        ]);

        // 3. Create Articles first so relations can link
        $article1 = new SeoArticle;
        $article1->site_id = (int) $site->id;
        $article1->user_id = (int) $staff->id;
        $article1->title = 'Hướng dẫn chăm sóc da mùa hè';
        $article1->slug = 'cham-soc-da-mua-he';
        $article1->language = 'vi';
        $article1->status = 'published';
        $article1->body = '<h1>Chăm sóc da</h1><p>Nội dung chi tiết...</p>';
        $article1->saveQuietly();
        SeoArticleProfile::query()->create([
            'article_id' => $article1->id,
            'focus_keyword' => 'chăm sóc da',
            'canonical_url' => 'https://client-shop.test/cham-soc-da-mua-he',
        ]);

        $article2 = new SeoArticle;
        $article2->site_id = (int) $site->id;
        $article2->user_id = (int) $staff->id;
        $article2->title = 'Kem chống nắng tốt nhất';
        $article2->slug = 'kem-chong-nang-tot-nhat';
        $article2->language = 'vi';
        $article2->status = 'published';
        $article2->body = '<h1>Kem chống nắng</h1><p>Top sản phẩm...</p>';
        $article2->saveQuietly();

        // 4. Create Article Children
        ArticleMeta::query()->create([
            'article_id' => $article1->id,
            'meta_key' => 'custom_rating',
            'meta_value' => '4.8',
        ]);

        $h1 = SeoArticleHeading::query()->create([
            'article_id' => $article1->id,
            'level' => 1,
            'heading_text' => 'Phần 1: Khởi đầu',
            'heading_slug' => 'phan-1',
            'sort_order' => 1,
        ]);

        SeoArticleHeading::query()->create([
            'article_id' => $article1->id,
            'parent_id' => $h1->id,
            'level' => 2,
            'heading_text' => 'Phần 1.1: Chi tiết',
            'heading_slug' => 'phan-1-1',
            'sort_order' => 2,
        ]);

        SeoFaq::query()->create([
            'article_id' => $article1->id,
            'question' => 'Nên bôi kem chống nắng khi nào?',
            'answer' => 'Trước khi ra ngoài 20 phút.',
            'sort_order' => 1,
        ]);

        SeoArticleReview::query()->create([
            'article_id' => $article1->id,
            'reviewer_id' => $owner->id,
            'action_type' => 'approve',
            'from_status' => 'in_review',
            'to_status' => 'approved',
            'reviewer_role' => 'owner',
            'note' => 'Bài viết rất tốt.',
        ]);

        // 5. Create Keywords with Semantic site states
        $kw = Keyword::query()->create([
            'phrase' => 'chăm sóc da mùa hè',
            'type' => 'normal',
            'source' => 'manual',
            'review_status' => 'approved',
        ]);

        $repo = app(KeywordMetaRepository::class);
        $repo->setSiteTargetUrl((int) $kw->id, (int) $site->id, 'https://client-shop.test/cham-soc-da-mua-he');
        $repo->setSiteSearchVolume((int) $kw->id, (int) $site->id, 5400);
        $repo->set((int) $kw->id, KeywordMetaKey::siteDifficulty((int) $site->id), '32.5');
        $repo->set((int) $kw->id, KeywordMetaKey::siteRescrapeKeep((int) $site->id), '1');
        $repo->set((int) $kw->id, KeywordMetaKey::siteMainArticleId((int) $site->id), (string) $article1->id);
        $repo->set((int) $kw->id, KeywordMetaKey::Tags->value, json_encode(['Skincare', 'MuaHe']));

        // 6. Create Search Intelligence entities
        SeoSiteKeyword::query()->create([
            'site_id' => $site->id,
            'keyword_id' => $kw->id,
            'phrase_kind' => 'informational',
            'is_seo_keyword' => true,
            'keyword_score' => 85.5,
        ]);

        $topic = SeoTopic::query()->create([
            'site_id' => $site->id,
            'name' => 'Chăm sóc da',
            'source' => 'manual',
            'status' => 'active',
            'is_locked' => true,
        ]);

        $tag = SeoTopicTag::query()->create([
            'site_id' => $site->id,
            'name' => 'Làm đẹp',
            'slug' => 'lam-dep',
        ]);

        SeoTopicKeyword::query()->create([
            'site_id' => $site->id,
            'topic_id' => $topic->id,
            'keyword_id' => $kw->id,
            'is_seed' => true,
            'is_locked' => true,
            'confidence' => 0.95,
        ]);

        SeoTopicTagAssignment::query()->create([
            'topic_id' => $topic->id,
            'tag_id' => $tag->id,
            'source' => 'manual',
        ]);

        SeoTopicKeywordDna::query()->create([
            'site_id' => $site->id,
            'topic_id' => $topic->id,
            'keyword_id' => $kw->id,
            'value' => 'skincare-primary',
            'facet_type' => 'category',
        ]);

        // 7. Create Links
        SeoSiteManualLink::query()->create([
            'site_id' => $site->id,
            'keyword' => 'chăm sóc da',
            'url' => 'https://client-shop.test/cham-soc-da',
            'url_hash' => md5('https://client-shop.test/cham-soc-da'),
            'is_locked' => true,
        ]);

        SeoSiteLinkExclusion::query()->create([
            'site_id' => $site->id,
            'url' => 'https://client-shop.test/privacy-policy',
            'url_hash' => md5('https://client-shop.test/privacy-policy'),
            'reason' => 'Policy page exclusion',
        ]);

        SeoLinkMap::query()->create([
            'keyword_id' => $kw->id,
            'source_article_id' => $article1->id,
            'target_article_id' => $article2->id,
            'target_site_id' => $site->id,
            'anchor_text' => 'kem chống nắng',
            'link_type' => 'internal',
            'status' => 'active',
            'destination_kind' => 'content',
        ]);

        // 8. Create Projects
        $draftProject = SeoProject::query()->create([
            'site_id' => $site->id,
            'user_id' => $owner->id,
            'name' => 'Dự thảo Q3',
            'status' => 'draft',
            'kind' => 'monthly',
        ]);

        $activeProject = SeoProject::query()->create([
            'site_id' => $site->id,
            'user_id' => $owner->id,
            'source_draft_project_id' => $draftProject->id,
            'name' => 'Kế hoạch SEO Tháng 10',
            'status' => 'approved',
            'kind' => 'monthly',
            'total_tasks' => 1,
        ]);

        SeoProjectTask::query()->create([
            'project_id' => $activeProject->id,
            'site_id' => $site->id,
            'article_id' => $article1->id,
            'keyword' => 'chăm sóc da mùa hè',
            'title' => 'Viết bài chăm sóc da mùa hè',
            'type' => 'create',
            'status' => 'completed',
        ]);

        // 9. WordPress link & Publishing state
        WordpressArticleLink::query()->create([
            'article_id' => $article1->id,
            'site_id' => $site->id,
            'wp_post_id' => 9999,
        ]);

        PublishingArticleState::query()->create([
            'article_id' => $article1->id,
            'platform' => 'primary',
            'publication_status' => 'published',
            'published_at' => now(),
        ]);

        // 10. Media
        $media = new SeoMedia;
        $media->site_id = (int) $site->id;
        $media->filename = 'kem-chong-nang.jpg';
        $media->slug = 'kem-chong-nang';
        $media->path = 'uploads/seo_media/kem-chong-nang.jpg';
        $media->url = 'https://client-shop.test/media/kem-chong-nang.jpg';
        $media->source = 'upload';
        $media->alt_text = 'Kem chống nắng';
        $media->status = 'ready';
        $media->wp_attachment_id = 4321;
        $media->setAttribute('article_id', [(int) $article1->id, (int) $article2->id]);
        $media->save();

        // EXECUTE EXPORT
        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'full_round_trip.zip';
        $exporter = new ClientTransferExporter;
        $exportResult = $exporter->export($zipPath);

        self::assertFileExists($zipPath);
        self::assertGreaterThanOrEqual(10, count($exportResult['counts']));

        // WIPE TARGET BUSINESS DATABASE (simulating fresh client)
        $this->wipeBusinessTables();
        self::assertSame(0, SeoArticle::query()->count());
        self::assertSame(0, Keyword::query()->count());
        self::assertSame(0, SeoTopic::query()->count());
        self::assertSame(0, SeoProject::query()->count());

        // Offset auto-increment on target to prove foreign keys are mapped to fresh target IDs, not original IDs
        DB::statement("INSERT OR REPLACE INTO sqlite_sequence (name, seq) VALUES ('articles', 500)");
        DB::statement("INSERT OR REPLACE INTO sqlite_sequence (name, seq) VALUES ('keywords', 700)");
        DB::statement("INSERT OR REPLACE INTO sqlite_sequence (name, seq) VALUES ('seo_topics', 800)");

        // EXECUTE IMPORT
        $importer = new ClientTransferImporter;
        $importResult = $importer->import($zipPath);

        // VERIFY ZERO FAILURES AND CLEAN STATS
        self::assertSame(0, $importResult['total_failed'], 'Import should have 0 failed records.');
        self::assertSame(0, $importResult['total_blocked'], 'Import should have 0 blocked records.');
        self::assertGreaterThanOrEqual(15, $importResult['total_imported']);

        // VERIFY CANONICAL BUSINESS RECONSTRUCTION & LOGICAL RELATIONSHIPS
        // 1. Articles
        $newArt1 = SeoArticle::query()->where('slug', 'cham-soc-da-mua-he')->firstOrFail();
        $newArt2 = SeoArticle::query()->where('slug', 'kem-chong-nang-tot-nhat')->firstOrFail();
        self::assertNotSame($article1->id, $newArt1->id, 'Target IDs are fresh and remapped.');
        self::assertGreaterThan(500, $newArt1->id);
        self::assertSame($staff->id, $newArt1->user_id);
        self::assertSame('chăm sóc da', $newArt1->seoProfile?->focus_keyword);
        self::assertSame('https://client-shop.test/cham-soc-da-mua-he', $newArt1->seoProfile?->canonical_url);

        // 2. Article Headings parent resolved
        $newH1 = SeoArticleHeading::query()->where('article_id', $newArt1->id)->where('level', 1)->firstOrFail();
        $newH2 = SeoArticleHeading::query()->where('article_id', $newArt1->id)->where('level', 2)->firstOrFail();
        self::assertSame($newH1->id, $newH2->parent_id, 'Heading parent_id resolved via deferred ref.');
        self::assertSame('Phần 1: Khởi đầu', $newH1->heading_text);
        self::assertSame('phan-1', $newH1->heading_slug);

        $newReview = SeoArticleReview::query()->where('article_id', $newArt1->id)->firstOrFail();
        self::assertSame('approve', $newReview->action_type);
        self::assertSame('in_review', $newReview->from_status);
        self::assertSame('approved', $newReview->to_status);
        self::assertSame('owner', $newReview->reviewer_role);
        self::assertSame('Bài viết rất tốt.', $newReview->note);

        // 3. Keywords & Semantic Metas
        $newKw = Keyword::query()->where('phrase', 'chăm sóc da mùa hè')->firstOrFail();
        $newSite = Site::query()->where('domain', 'client-shop.test')->firstOrFail();

        $importedTargetUrl = $repo->getSiteTargetUrl((int) $newKw->id, (int) $newSite->id);
        $importedVolume = $repo->getSiteSearchVolume((int) $newKw->id, (int) $newSite->id);
        $importedMainArticleId = $repo->get((int) $newKw->id, KeywordMetaKey::siteMainArticleId((int) $newSite->id));

        self::assertSame('https://client-shop.test/cham-soc-da-mua-he', $importedTargetUrl);
        self::assertSame(5400, $importedVolume);
        self::assertSame((string) $newArt1->id, $importedMainArticleId, 'Keyword site focus article resolved to fresh article target ID.');

        // 4. Topic Keyword membership
        $newTopic = SeoTopic::query()->where('name', 'Chăm sóc da')->firstOrFail();
        $membership = SeoTopicKeyword::query()->where('topic_id', $newTopic->id)->where('keyword_id', $newKw->id)->firstOrFail();
        self::assertTrue((bool) $membership->is_seed);

        // 5. Link Map target article resolved
        $newLinkMap = SeoLinkMap::query()->where('keyword_id', $newKw->id)->firstOrFail();
        self::assertSame($newArt1->id, $newLinkMap->source_article_id);
        self::assertSame($newArt2->id, $newLinkMap->target_article_id, 'Link map target article resolved.');

        // 6. Project draft project self-ref resolved
        $newDraft = SeoProject::query()->where('name', 'Dự thảo Q3')->firstOrFail();
        $newActive = SeoProject::query()->where('name', 'Kế hoạch SEO Tháng 10')->firstOrFail();
        self::assertSame($newDraft->id, $newActive->source_draft_project_id, 'Project source_draft_project_id resolved via deferred ref.');

        // 7. Project task article resolved
        $newTask = SeoProjectTask::query()->where('project_id', $newActive->id)->firstOrFail();
        self::assertSame($newArt1->id, $newTask->article_id, 'Task article_id resolved.');

        // 8. WordPress link
        $newWpLink = WordpressArticleLink::query()->where('article_id', $newArt1->id)->firstOrFail();
        self::assertSame(9999, $newWpLink->wp_post_id);

        // 9. Media auxiliary article IDs
        $newMedia = SeoMedia::query()->where('filename', 'kem-chong-nang.jpg')->firstOrFail();
        self::assertSame($newArt1->id, $newMedia->primary_article_id);
        $loadedAux = $newMedia->getAttribute('article_id');
        self::assertIsArray($loadedAux);
        self::assertContains($newArt1->id, $loadedAux);
        self::assertContains($newArt2->id, $loadedAux);
        self::assertSame('Kem chống nắng', $newMedia->alt_text);
        self::assertSame('ready', $newMedia->status);
        self::assertSame(4321, $newMedia->wp_attachment_id);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\ClientTransfer;

use App\Models\ClientTransferRun;
use App\Models\Site;
use App\Models\User;
use App\Services\ClientTransfer\ClientTransferExporter;
use App\Services\ClientTransfer\ClientTransferImporter;
use App\Services\ClientTransfer\Exceptions\TargetNotEmptyException;
use App\Services\ClientTransfer\FailureRequestInspector;
use App\Services\ClientTransfer\Manifest\TransferManifest;
use App\Services\ClientTransfer\RetryDataPackageExporter;
use App\Services\ClientTransfer\Support\ZipArchiveManager;
use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleHeading;
use Omnichannel\Addons\Content\Models\SeoFaq;
use Tests\Unit\ClientTransfer\TransferDatabaseTestCase;

final class RetryFlowTest extends TransferDatabaseTestCase
{
    public function test_complete_retry_roundtrip_flow(): void
    {
        // ==========================================
        // 1. SETUP SOURCE SYSTEM
        // ==========================================
        $user = User::query()->create(['name' => 'Source User', 'email' => 'user@source.test']);
        $site = Site::query()->create(['domain' => 'source-site.test', 'user_id' => $user->id, 'status' => 'active']);

        // Article 1: Good article (will import fine on target)
        $goodArticle = new SeoArticle;
        $goodArticle->site_id = (int) $site->id;
        $goodArticle->user_id = (int) $user->id;
        $goodArticle->title = 'Good Article';
        $goodArticle->slug = 'good-article';
        $goodArticle->body = '<p>Initial good content</p>';
        $goodArticle->saveQuietly();

        // Article 2: Bad article on initial import (corrupted body blob during transit)
        $badArticle = new SeoArticle;
        $badArticle->site_id = (int) $site->id;
        $badArticle->user_id = (int) $user->id;
        $badArticle->title = 'Bad Article Initially';
        $badArticle->slug = 'bad-article-slug';
        $badArticle->body = '<p>Original canonical body on source</p>';
        $badArticle->saveQuietly();

        // Child records of Bad Article (should be blocked when bad article fails)
        $badHeading = SeoArticleHeading::query()->create([
            'article_id' => $badArticle->id,
            'level' => 1,
            'heading_text' => 'Heading of Bad Article',
            'heading_slug' => 'heading-of-bad-article',
        ]);

        $badFaq = SeoFaq::query()->create([
            'article_id' => $badArticle->id,
            'question' => 'FAQ of Bad Article?',
            'answer' => 'Answer of Bad Article.',
        ]);

        $badMeta = ArticleMeta::query()->create([
            'article_id' => $badArticle->id,
            'meta_key' => 'meta_key_bad',
            'meta_value' => 'meta_val_bad',
        ]);

        // ==========================================
        // 2. SOURCE: FULL EXPORT
        // ==========================================
        $fullExportZip = $this->tempDir.DIRECTORY_SEPARATOR.'full_export.zip';
        (new ClientTransferExporter)->export($fullExportZip);

        // Tamper full package to simulate corrupted blob for Bad Article during transit
        $tamperDir = $this->tempDir.DIRECTORY_SEPARATOR.'tamper_staging';
        ZipArchiveManager::extractAndValidate($fullExportZip, $tamperDir);

        $articlesNdjson = $tamperDir.'/content/articles/part-000001.ndjson';
        $lines = file($articlesNdjson, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line) {
            $rec = json_decode($line, true);
            if ($rec['ref'] === 'article:'.$badArticle->id) {
                $blobPath = $tamperDir.'/'.str_replace('/', DIRECTORY_SEPARATOR, $rec['body_blob']);
                file_put_contents($blobPath, '<p>CORRUPTED BODY CORRUPTED</p>');
            }
        }
        $corruptedFullZip = $this->tempDir.DIRECTORY_SEPARATOR.'corrupted_full_export.zip';
        ZipArchiveManager::create($tamperDir, $corruptedFullZip);

        // Keep source DB backed up in source.sqlite
        $sourceDbFile = $this->tempDir.DIRECTORY_SEPARATOR.'source.sqlite';
        copy(config('database.connections.sqlite.database'), $sourceDbFile);

        // ==========================================
        // 3. TARGET: INITIAL IMPORT
        // ==========================================
        // Switch DB connection to target.sqlite
        $targetDbFile = $this->tempDir.DIRECTORY_SEPARATOR.'target.sqlite';
        touch($targetDbFile);
        config()->set('database.connections.sqlite.database', $targetDbFile);
        config()->set('database.connections.omi_seo_ai.database', $targetDbFile);
        \Illuminate\Support\Facades\DB::purge('sqlite');
        \Illuminate\Support\Facades\DB::purge('omi_seo_ai');
        $this->createSchema();

        \Illuminate\Support\Facades\DB::statement("INSERT OR REPLACE INTO sqlite_sequence (name, seq) VALUES ('users', 100)");
        \Illuminate\Support\Facades\DB::statement("INSERT OR REPLACE INTO sqlite_sequence (name, seq) VALUES ('sites', 200)");
        \Illuminate\Support\Facades\DB::statement("INSERT OR REPLACE INTO sqlite_sequence (name, seq) VALUES ('articles', 500)");

        $importer = new ClientTransferImporter;
        $importResult = $importer->import($corruptedFullZip);

        self::assertGreaterThanOrEqual(1, $importResult['total_imported']);
        self::assertGreaterThanOrEqual(1, $importResult['total_failed']);
        self::assertGreaterThanOrEqual(3, $importResult['total_blocked']);
        self::assertNotNull($importResult['retry_package_path']);

        $failureZip = $importResult['retry_package_path'];
        $originalRunId = $importResult['run_id'];

        // Confirm good article exists on target
        $targetGoodArticle = SeoArticle::query()->where('slug', 'good-article')->first();
        self::assertNotNull($targetGoodArticle);
        $targetGoodId = $targetGoodArticle->id;

        // Confirm bad article and children DO NOT exist on target yet
        self::assertNull(SeoArticle::query()->where('slug', 'bad-article-slug')->first());
        self::assertSame(0, SeoArticleHeading::query()->count());
        self::assertSame(0, SeoFaq::query()->count());
        self::assertSame(0, ArticleMeta::query()->count());

        // ==========================================
        // 4. FAILURE REQUEST INSPECTION (Check 1)
        // ==========================================
        $inspector = new FailureRequestInspector;
        $inspection = $inspector->inspect($failureZip);

        self::assertSame($originalRunId, $inspection['original_import_run_id']);
        self::assertGreaterThanOrEqual(1, $inspection['failed_roots']);
        self::assertGreaterThanOrEqual(3, $inspection['blocked']);
        self::assertContains('articles', $inspection['datasets']);
        self::assertContains('article:'.$badArticle->id, $inspection['refs']['articles']);
        self::assertContains('heading:'.$badHeading->id, $inspection['refs']['article_headings']);
        self::assertContains('faq:'.$badFaq->id, $inspection['refs']['article_faqs']);
        self::assertContains('article_meta:'.$badMeta->id, $inspection['refs']['article_meta']);

        // ==========================================
        // 5. CANONICAL UPDATE ON SOURCE DB (Check 2 & 3)
        // ==========================================
        // Switch context to SOURCE database
        config()->set('database.connections.sqlite.database', $sourceDbFile);
        config()->set('database.connections.omi_seo_ai.database', $sourceDbFile);
        \Illuminate\Support\Facades\DB::purge('sqlite');
        \Illuminate\Support\Facades\DB::purge('omi_seo_ai');

        $sourceBadArticle = SeoArticle::query()->where('id', $badArticle->id)->firstOrFail();
        $sourceBadArticle->title = 'Bad Article Fixed Canonical Title';
        $sourceBadArticle->body = '<p>Fresh Canonical Body On Source DB</p>';
        $sourceBadArticle->saveQuietly();

        // Also delete an unneeded record to test missing/deleted source ref reporting (Check 4)
        SeoFaq::query()->where('id', $badFaq->id)->delete();

        // ==========================================
        // 6. SOURCE: RETRY DATA EXPORT
        // ==========================================
        $retryExporter = new RetryDataPackageExporter;
        $retryExportResult = $retryExporter->export($failureZip);

        $retryDataZip = $retryExportResult['destination_path'];
        self::assertFileExists($retryDataZip);
        self::assertArrayHasKey('articles', $retryExportResult['counts']);
        self::assertSame(1, $retryExportResult['counts']['articles']);
        self::assertContains('faq:'.$badFaq->id, $retryExportResult['unresolvable_refs'], 'Missing source ref must be reported');

        // Check 6: Retry Data manifest is distinct
        $retryInspectDir = $this->tempDir.DIRECTORY_SEPARATOR.'retry_data_inspect';
        $retryManifestData = ZipArchiveManager::extractAndValidate($retryDataZip, $retryInspectDir);
        /** @var TransferManifest $retryManifest */
        $retryManifest = $retryManifestData['manifest'];
        self::assertTrue($retryManifest->isRetryData());
        self::assertFalse($retryManifest->isFailureRequest());
        self::assertSame($originalRunId, $retryManifest->originalImportRunId());

        // Check 3: Ensure Retry Data ndjson contains FRESH source canonical data (not stale)
        $retryArticlesFile = $retryInspectDir.'/content/articles/part-000001.ndjson';
        self::assertFileExists($retryArticlesFile);
        $retryArticleRow = json_decode(trim((string) file_get_contents($retryArticlesFile)), true);
        self::assertSame('Bad Article Fixed Canonical Title', $retryArticleRow['title']);
        $blobContent = file_get_contents($retryInspectDir.'/'.str_replace('/', DIRECTORY_SEPARATOR, $retryArticleRow['body_blob']));
        self::assertSame('<p>Fresh Canonical Body On Source DB</p>', $blobContent);

        // ==========================================
        // 7. TARGET: PREFLIGHT FOR NORMAL IMPORT VS RETRY DATA IMPORT (Check 7 & 8)
        // ==========================================
        // Switch context back to TARGET database
        config()->set('database.connections.sqlite.database', $targetDbFile);
        config()->set('database.connections.omi_seo_ai.database', $targetDbFile);
        \Illuminate\Support\Facades\DB::purge('sqlite');
        \Illuminate\Support\Facades\DB::purge('omi_seo_ai');
        $preflightNormal = $importer->inspect($fullExportZip);
        self::assertFalse($preflightNormal['target_empty'], 'Normal full package detects target is not empty');
        self::assertFalse($preflightNormal['is_retry_data']);

        try {
            $importer->assertTargetReadyForImport($preflightNormal['manifest'], force: false);
            self::fail('Normal full export should fail preflight when target is not empty');
        } catch (TargetNotEmptyException $e) {
            self::assertStringContainsString('contains existing records', $e->getMessage());
        }

        $preflightRetry = $importer->inspect($retryDataZip);
        self::assertTrue($preflightRetry['is_retry_data']);
        self::assertSame($originalRunId, $preflightRetry['original_import_run_id']);

        // Retry data preflight succeeds even when target is partially populated
        $importer->assertTargetReadyForImport($preflightRetry['manifest'], force: false);

        // ==========================================
        // 8. TARGET: EXECUTE RETRY IMPORT (Check 8, 9, 10, 11)
        // ==========================================
        // Verify original refmap sqlite exists in storage for original run
        $originalRefmapPath = storage_path("app/client-transfer/refmap_{$originalRunId}.sqlite");
        self::assertFileExists($originalRefmapPath, 'Original refmap SQLite database must be preserved on failure');

        $retryImportResult = $importer->import($retryDataZip);

        self::assertSame(0, $retryImportResult['total_failed'], 'Retry import should have 0 failures');

        // Check 10: Existing successful records are not duplicated
        self::assertSame(2, SeoArticle::query()->count(), 'Target must have exactly 2 articles (good + retried bad)');
        self::assertSame($targetGoodId, SeoArticle::query()->where('slug', 'good-article')->first()->id);

        // Check 5 & 9 & 11: Retried article and its child records imported and resolved against persisted original mappings
        $retriedArticle = SeoArticle::query()->where('slug', 'bad-article-slug')->first();
        self::assertNotNull($retriedArticle);
        self::assertSame('Bad Article Fixed Canonical Title', $retriedArticle->title);
        self::assertSame('<p>Fresh Canonical Body On Source DB</p>', $retriedArticle->body);
        // Verify target author ID mapped to target user (which has different ID than source user)
        $targetUser = User::query()->where('email', 'user@source.test')->firstOrFail();
        self::assertNotSame($user->id, $targetUser->id, 'Target user ID differs from source user ID');
        self::assertSame($targetUser->id, $retriedArticle->user_id);
        self::assertNotSame($badArticle->id, $retriedArticle->id, 'Target article ID differs from source article ID');

        // Check blocked children (headings, meta) now successfully created and linked to target article ID
        $retriedHeading = SeoArticleHeading::query()->where('article_id', $retriedArticle->id)->first();
        self::assertNotNull($retriedHeading);
        self::assertSame('Heading of Bad Article', $retriedHeading->heading_text);

        $retriedMeta = ArticleMeta::query()->where('article_id', $retriedArticle->id)->first();
        self::assertNotNull($retriedMeta);
        self::assertSame('meta_key_bad', $retriedMeta->meta_key);
        self::assertSame('meta_val_bad', $retriedMeta->meta_value);
    }

    public function test_queued_retry_export_and_import_pipeline(): void
    {
        // 1. Setup Source DB
        $sourceDbFile = $this->tempDir.DIRECTORY_SEPARATOR.'queued_source.sqlite';
        touch($sourceDbFile);
        config()->set('database.connections.sqlite.database', $sourceDbFile);
        config()->set('database.connections.omi_seo_ai.database', $sourceDbFile);
        \Illuminate\Support\Facades\DB::purge('sqlite');
        \Illuminate\Support\Facades\DB::purge('omi_seo_ai');
        $this->createSchema();

        $user = User::query()->create(['name' => 'Queued Author', 'email' => 'author@queued.test']);
        $site = Site::query()->create(['domain' => 'queued.test', 'user_id' => $user->id, 'status' => 'active']);

        $article = new SeoArticle;
        $article->site_id = (int) $site->id;
        $article->user_id = (int) $user->id;
        $article->title = 'Queued Article 1';
        $article->slug = 'queued-art-1';
        $article->body = '<p>Initial Queued Body</p>';
        $article->saveQuietly();

        // 2. Full Export
        $exportZip = $this->tempDir.DIRECTORY_SEPARATOR.'queued_export.zip';
        (new ClientTransferExporter)->export($exportZip);

        // Corrupt blob to trigger failure on target
        $tamperDir = $this->tempDir.DIRECTORY_SEPARATOR.'queued_tamper';
        ZipArchiveManager::extractAndValidate($exportZip, $tamperDir);
        $articlesNdjson = $tamperDir.'/content/articles/part-000001.ndjson';
        $lines = file($articlesNdjson, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line) {
            $rec = json_decode($line, true);
            if ($rec['ref'] === 'article:'.$article->id) {
                file_put_contents($tamperDir.'/'.str_replace('/', DIRECTORY_SEPARATOR, $rec['body_blob']), '<p>CORRUPTED</p>');
            }
        }
        $corruptedZip = $this->tempDir.DIRECTORY_SEPARATOR.'queued_corrupted.zip';
        ZipArchiveManager::create($tamperDir, $corruptedZip);

        // 3. Target setup and initial queued import
        $targetDbFile = $this->tempDir.DIRECTORY_SEPARATOR.'queued_target.sqlite';
        touch($targetDbFile);
        config()->set('database.connections.sqlite.database', $targetDbFile);
        config()->set('database.connections.omi_seo_ai.database', $targetDbFile);
        \Illuminate\Support\Facades\DB::purge('sqlite');
        \Illuminate\Support\Facades\DB::purge('omi_seo_ai');
        $this->createSchema();

        $importRunId = 'q-imp-'.\Illuminate\Support\Str::random(8);
        $importRun = ClientTransferRun::query()->create([
            'run_id' => $importRunId,
            'type' => 'import',
            'status' => 'pending',
            'phase' => 'queued',
        ]);
        \App\Jobs\ClientTransfer\PrepareSeoImportJob::dispatchSync($importRunId, $corruptedZip);

        $importRun->refresh();
        self::assertTrue($importRun->isCompleted());
        self::assertGreaterThanOrEqual(1, $importRun->failed_count);
        self::assertNotNull($importRun->retry_package_path);
        self::assertFileExists($importRun->retry_package_path);
        $failureZip = $importRun->retry_package_path;

        // 4. Source: update canonical data and run queued retry export job
        config()->set('database.connections.sqlite.database', $sourceDbFile);
        config()->set('database.connections.omi_seo_ai.database', $sourceDbFile);
        \Illuminate\Support\Facades\DB::purge('sqlite');
        \Illuminate\Support\Facades\DB::purge('omi_seo_ai');

        $sourceArticle = SeoArticle::query()->where('id', $article->id)->firstOrFail();
        $sourceArticle->title = 'Queued Fixed Canonical Title';
        $sourceArticle->body = '<p>Queued Fixed Canonical Body</p>';
        $sourceArticle->saveQuietly();

        $retryExportRunId = 'q-ret-'.\Illuminate\Support\Str::random(8);
        $retryExportRun = ClientTransferRun::query()->create([
            'run_id' => $retryExportRunId,
            'type' => 'retry_export',
            'status' => 'pending',
            'phase' => 'queued',
        ]);

        \App\Jobs\ClientTransfer\BuildRetryDataPackageJob::dispatchSync($retryExportRunId, $failureZip);
        $retryExportRun->refresh();
        self::assertTrue($retryExportRun->isCompleted());
        self::assertNotNull($retryExportRun->artifact_path);
        self::assertFileExists($retryExportRun->artifact_path);
        $retryDataZip = $retryExportRun->artifact_path;

        // 5. Target: run queued retry import job
        config()->set('database.connections.sqlite.database', $targetDbFile);
        config()->set('database.connections.omi_seo_ai.database', $targetDbFile);
        \Illuminate\Support\Facades\DB::purge('sqlite');
        \Illuminate\Support\Facades\DB::purge('omi_seo_ai');

        $retryImportRunId = 'q-rimp-'.\Illuminate\Support\Str::random(8);
        $retryImportRun = ClientTransferRun::query()->create([
            'run_id' => $retryImportRunId,
            'type' => 'import',
            'status' => 'pending',
            'phase' => 'queued',
        ]);

        \App\Jobs\ClientTransfer\PrepareSeoImportJob::dispatchSync($retryImportRunId, $retryDataZip);
        $retryImportRun->refresh();
        self::assertTrue($retryImportRun->isCompleted());
        self::assertSame(0, $retryImportRun->failed_count);
        self::assertGreaterThanOrEqual(1, $retryImportRun->imported_count);

        $savedArt = SeoArticle::query()->where('slug', 'queued-art-1')->first();
        self::assertNotNull($savedArt);
        self::assertSame('Queued Fixed Canonical Title', $savedArt->title);
        self::assertSame('<p>Queued Fixed Canonical Body</p>', $savedArt->body);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\ClientTransfer;

use App\Models\Site;
use App\Models\User;
use App\Services\ClientTransfer\ClientTransferExporter;
use App\Services\ClientTransfer\ClientTransferImporter;
use App\Services\ClientTransfer\Exceptions\TargetNotEmptyException;
use App\Services\ClientTransfer\Manifest\TransferManifest;
use App\Services\ClientTransfer\Support\ZipArchiveManager;
use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleHeading;
use Omnichannel\Addons\Content\Models\SeoFaq;
use Tests\Unit\ClientTransfer\TransferDatabaseTestCase;

final class ImportFailureAndQuarantineTest extends TransferDatabaseTestCase
{
    public function test_target_not_empty_refuses_import_unless_forced(): void
    {
        $site = Site::query()->create(['domain' => 'test-target.test', 'status' => 'active']);

        $article = new SeoArticle;
        $article->site_id = (int) $site->id;
        $article->title = 'Existing Article';
        $article->slug = 'existing-article';
        $article->saveQuietly();

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'test_target.zip';
        $exporter = new ClientTransferExporter;
        $exporter->export($zipPath);

        $importer = new ClientTransferImporter;

        // 1. Without force -> Throws TargetNotEmptyException
        $this->expectException(TargetNotEmptyException::class);
        $importer->import($zipPath, force: false);
    }

    public function test_target_not_empty_succeeds_when_force_is_true(): void
    {
        $site = Site::query()->create(['domain' => 'forced-target.test', 'status' => 'active']);

        $article = new SeoArticle;
        $article->site_id = (int) $site->id;
        $article->title = 'Initial Article';
        $article->slug = 'initial-article';
        $article->saveQuietly();

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'forced_target.zip';
        $exporter = new ClientTransferExporter;
        $exporter->export($zipPath);

        // Wipe articles so import can insert cleanly with force
        $this->wipeBusinessTables();
        // Insert a dummy row to make database non-empty
        SeoArticle::query()->insert([
            'site_id' => 1,
            'title' => 'Dummy Non Empty Indicator',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $importer = new ClientTransferImporter;
        $res = $importer->import($zipPath, force: true);

        self::assertSame(1, $res['dataset_stats']['articles']['imported']);
    }

    public function test_one_bad_article_does_not_kill_safe_unrelated_records_and_blocks_children_cleanly(): void
    {
        $user = User::query()->create(['name' => 'Test User', 'email' => 'user@quarantine.test']);
        $site = Site::query()->create(['domain' => 'quarantine.test', 'user_id' => $user->id, 'status' => 'active']);

        // Article 1: Safe
        $goodArticle = new SeoArticle;
        $goodArticle->site_id = (int) $site->id;
        $goodArticle->title = 'Good Article';
        $goodArticle->slug = 'good-article';
        $goodArticle->body = '<p>Good content</p>';
        $goodArticle->saveQuietly();

        // Article 2: Will be tampered in package to become malformed / bad blob
        $badArticle = new SeoArticle;
        $badArticle->site_id = (int) $site->id;
        $badArticle->title = 'Bad Article Target';
        $badArticle->slug = 'bad-article-target';
        $badArticle->body = '<p>Bad content to be corrupted</p>';
        $badArticle->saveQuietly();

        // Add children to Bad Article
        ArticleMeta::query()->create([
            'article_id' => $badArticle->id,
            'meta_key' => 'seo_meta_desc',
            'meta_value' => 'Sample description',
        ]);

        SeoArticleHeading::query()->create([
            'article_id' => $badArticle->id,
            'level' => 2,
            'heading_text' => 'Bad Article Heading',
            'heading_slug' => 'bad-article-heading',
        ]);

        SeoFaq::query()->create([
            'article_id' => $badArticle->id,
            'question' => 'Sample FAQ Question?',
            'answer' => 'Sample FAQ Answer.',
        ]);

        // Export original package
        $originalZip = $this->tempDir.DIRECTORY_SEPARATOR.'original_package.zip';
        $exporter = new ClientTransferExporter;
        $exporter->export($originalZip);

        // Tamper with the package: corrupt Bad Article's body blob file so checksum fails
        $extractDir = $this->tempDir.DIRECTORY_SEPARATOR.'tamper_staging';
        ZipArchiveManager::extractAndValidate($originalZip, $extractDir);

        // Find Bad Article's body blob and corrupt its bytes
        $articlesPartFile = $extractDir.'/content/articles/part-000001.ndjson';
        $lines = file($articlesPartFile, FILE_IGNORE_NEW_LINES);
        $newLines = [];
        $tamperedBlobPath = null;
        foreach ($lines as $line) {
            $rec = json_decode($line, true);
            if ($rec['ref'] === 'article:'.$badArticle->id) {
                $tamperedBlobPath = $extractDir.'/'.str_replace('/', DIRECTORY_SEPARATOR, $rec['body_blob']);
                file_put_contents($tamperedBlobPath, '<p>CORRUPTED BYTES THAT BREAK SHA256</p>');
            }
            $newLines[] = $line;
        }

        // Re-zip the tampered package (manifest has original sha, but blob file is corrupted)
        $tamperedZip = $this->tempDir.DIRECTORY_SEPARATOR.'tampered_package.zip';
        ZipArchiveManager::create($extractDir, $tamperedZip);

        // Wipe target business tables
        $this->wipeBusinessTables();

        // Run import
        $importer = new ClientTransferImporter;
        $res = $importer->import($tamperedZip);

        // Assertions:
        // 1. Total imported contains the good article
        self::assertGreaterThanOrEqual(1, $res['total_imported']);
        self::assertNotNull(SeoArticle::query()->where('slug', 'good-article')->first(), 'Good article must be imported successfully.');

        // 2. Bad article failed
        self::assertGreaterThanOrEqual(1, $res['total_failed']);
        self::assertNull(SeoArticle::query()->where('slug', 'bad-article-target')->first(), 'Bad article must not be in database.');

        // 3. Child records (headings, faqs, meta) are marked BLOCKED_BY_PARENT, NOT database errors
        self::assertGreaterThanOrEqual(3, $res['total_blocked']);
        self::assertSame(0, SeoArticleHeading::query()->count());
        self::assertSame(0, SeoFaq::query()->count());

        // 4. Quarantine retry package was created
        self::assertNotNull($res['retry_package_path']);
        self::assertFileExists($res['retry_package_path']);

        // Inspect quarantine package
        $quarantineInspectDir = $this->tempDir.DIRECTORY_SEPARATOR.'quarantine_inspect';
        $quarantineRes = ZipArchiveManager::extractAndValidate($res['retry_package_path'], $quarantineInspectDir);
        self::assertInstanceOf(TransferManifest::class, $quarantineRes['manifest']);
        self::assertFileExists($quarantineInspectDir.DIRECTORY_SEPARATOR.'import-errors.ndjson');

        // Verify import-errors.ndjson has structured log entries
        $errorLines = file($quarantineInspectDir.DIRECTORY_SEPARATOR.'import-errors.ndjson');
        self::assertNotEmpty($errorLines);
        $firstError = json_decode($errorLines[0], true);
        self::assertArrayHasKey('import_run_id', $firstError);
        self::assertArrayHasKey('status', $firstError);
    }

    public function test_secrets_are_never_written_to_export_package(): void
    {
        $user = User::query()->create([
            'name' => 'Secret User',
            'email' => 'secret@domain.test',
            'password' => '$2y$10$SUPER_SECRET_PASSWORD_HASH',
            'role' => 'admin',
        ]);

        $site = Site::query()->create(['domain' => 'secrets.test', 'user_id' => $user->id, 'status' => 'active']);

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'secret_check.zip';
        $exporter = new ClientTransferExporter;
        $exporter->export($zipPath);

        $inspectDir = $this->tempDir.DIRECTORY_SEPARATOR.'secret_inspect';
        ZipArchiveManager::extractAndValidate($zipPath, $inspectDir);

        // Check user export file does not contain password
        $userFile = $inspectDir.'/core/users/part-000001.ndjson';
        $userContent = file_get_contents($userFile);
        self::assertStringNotContainsString('SUPER_SECRET_PASSWORD_HASH', $userContent);
        self::assertStringNotContainsString('password', $userContent);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\ClientTransfer;

use App\Models\Site;
use App\Models\User;
use App\Services\ClientTransfer\ClientTransferExporter;
use App\Services\ClientTransfer\ClientTransferImporter;
use App\Services\ClientTransfer\Support\MediaBinaryManager;
use Illuminate\Support\Facades\Storage;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Tests\Unit\ClientTransfer\TransferDatabaseTestCase;
use ZipArchive;

final class MediaBinaryExportTest extends TransferDatabaseTestCase
{
    private User $user;

    private Site $site;

    private string $mediaRoot;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::forgetDisk('public');

        $this->user = User::query()->create([
            'name' => 'Media Admin',
            'email' => 'admin@media.test',
            'role' => 'admin',
        ]);

        $this->site = Site::query()->create([
            'domain' => 'media-export.test',
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        // Ensure disk 'public' root points to a clean directory in our tempDir
        $publicRoot = $this->tempDir.DIRECTORY_SEPARATOR.'storage_public';
        if (! is_dir($publicRoot)) {
            mkdir($publicRoot, 0755, true);
        }
        config()->set('filesystems.disks.public.root', $publicRoot);
        Storage::forgetDisk('public');

        $this->mediaRoot = $publicRoot.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'seo_media';
        if (! is_dir($this->mediaRoot)) {
            mkdir($this->mediaRoot, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('public');
        parent::tearDown();
    }

    public function test_laravel_media_binary_is_included_in_export_package(): void
    {
        $content = 'binary-image-data-sample-12345';
        $sha = hash('sha256', $content);
        $fileName = 'test-image.png';
        $relPath = 'uploads/seo_media/'.$fileName;
        file_put_contents($this->mediaRoot.DIRECTORY_SEPARATOR.$fileName, $content);

        $media = SeoMedia::query()->create([
            'site_id' => $this->site->id,
            'filename' => $fileName,
            'slug' => 'test-image',
            'path' => $relPath,
            'url' => '/storage/'.$relPath,
            'source' => 'upload',
            'status' => 'ready',
        ]);

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_included.zip';
        $exporter = new ClientTransferExporter;
        $result = $exporter->export($zipPath);

        self::assertFileExists($zipPath);
        self::assertSame(1, $result['counts']['media']);

        // Check entry in ZIP
        $zip = new ZipArchive;
        self::assertTrue($zip->open($zipPath));
        $entryName = "media/files/{$sha}.png";
        $extracted = $zip->getFromName($entryName);
        $zip->close();

        self::assertNotFalse($extracted, "ZIP should contain [{$entryName}]");
        self::assertSame($content, $extracted);

        // Check manifest stats
        $manifest = $result['manifest'];
        self::assertNotNull($manifest->media);
        self::assertSame(1, $manifest->media['stats']['media_files']);
        self::assertSame(strlen($content), $manifest->media['stats']['total_media_bytes']);
    }

    public function test_unpublished_ai_generated_media_is_included(): void
    {
        $aiContent = 'ai-generated-image-raw-data-unpublished';
        $sha = hash('sha256', $aiContent);
        $fileName = 'ai-pending-art.webp';
        $relPath = 'uploads/seo_media/'.$fileName;
        file_put_contents($this->mediaRoot.DIRECTORY_SEPARATOR.$fileName, $aiContent);

        SeoMedia::query()->create([
            'site_id' => $this->site->id,
            'filename' => $fileName,
            'slug' => 'ai-pending-art',
            'path' => $relPath,
            'url' => '/storage/'.$relPath,
            'source' => 'ai_prompt',
            'status' => 'pending',
            'wp_attachment_id' => null,
            'wp_synced_at' => null,
        ]);

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_ai.zip';
        $exporter = new ClientTransferExporter;
        $result = $exporter->export($zipPath);

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $extracted = $zip->getFromName("media/files/{$sha}.webp");
        $zip->close();

        self::assertSame($aiContent, $extracted);
    }

    public function test_media_with_null_article_relation_is_still_included(): void
    {
        $content = 'standalone-media-without-article';
        $sha = hash('sha256', $content);
        $fileName = 'standalone.jpg';
        $relPath = 'uploads/seo_media/'.$fileName;
        file_put_contents($this->mediaRoot.DIRECTORY_SEPARATOR.$fileName, $content);

        $media = SeoMedia::query()->create([
            'site_id' => $this->site->id,
            'filename' => $fileName,
            'slug' => 'standalone',
            'path' => $relPath,
            'url' => '/storage/'.$relPath,
            'source' => 'upload',
            'status' => 'ready',
        ]);
        // Explicitly ensure article_id is null
        $media->setAttribute('article_id', null);
        $media->save();

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_null_art.zip';
        $exporter = new ClientTransferExporter;
        $exporter->export($zipPath);

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $extracted = $zip->getFromName("media/files/{$sha}.jpg");
        $zip->close();

        self::assertSame($content, $extracted);
    }

    public function test_same_physical_binary_used_by_two_records_is_stored_once_by_sha256(): void
    {
        $sharedContent = 'shared-binary-content-across-two-records';
        $sha = hash('sha256', $sharedContent);

        // Record 1
        $file1 = 'copy-one.jpg';
        file_put_contents($this->mediaRoot.DIRECTORY_SEPARATOR.$file1, $sharedContent);
        SeoMedia::query()->create([
            'site_id' => $this->site->id,
            'filename' => $file1,
            'slug' => 'copy-one',
            'path' => 'uploads/seo_media/'.$file1,
            'url' => '/storage/uploads/seo_media/'.$file1,
            'source' => 'upload',
        ]);

        // Record 2 points to identical binary file
        $file2 = 'copy-two.jpg';
        file_put_contents($this->mediaRoot.DIRECTORY_SEPARATOR.$file2, $sharedContent);
        SeoMedia::query()->create([
            'site_id' => $this->site->id,
            'filename' => $file2,
            'slug' => 'copy-two',
            'path' => 'uploads/seo_media/'.$file2,
            'url' => '/storage/uploads/seo_media/'.$file2,
            'source' => 'upload',
        ]);

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_dedup.zip';
        $exporter = new ClientTransferExporter;
        $result = $exporter->export($zipPath);

        self::assertSame(2, $result['counts']['media']);
        $manifest = $result['manifest'];
        self::assertSame(1, $manifest->media['stats']['media_files']);
        self::assertSame(1, $manifest->media['stats']['deduplicated_files']);

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $countFiles = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'media/files/')) {
                $countFiles++;
            }
        }
        $zip->close();

        self::assertSame(1, $countFiles, 'Only 1 physical file should exist in media/files/');
    }

    public function test_binary_content_is_byte_for_byte_preserved_in_the_zip(): void
    {
        // Binary payload with arbitrary high and null bytes
        $bytes = random_bytes(4096);
        $sha = hash('sha256', $bytes);
        $fileName = 'binary-exact.png';
        file_put_contents($this->mediaRoot.DIRECTORY_SEPARATOR.$fileName, $bytes);

        SeoMedia::query()->create([
            'site_id' => $this->site->id,
            'filename' => $fileName,
            'slug' => 'binary-exact',
            'path' => 'uploads/seo_media/'.$fileName,
            'url' => '/storage/uploads/seo_media/'.$fileName,
            'source' => 'upload',
        ]);

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_byte_exact.zip';
        $exporter = new ClientTransferExporter;
        $exporter->export($zipPath);

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $fromZip = $zip->getFromName("media/files/{$sha}.png");
        $zip->close();

        self::assertSame($bytes, $fromZip);
        self::assertSame($sha, hash('sha256', $fromZip));
    }

    public function test_large_file_handling_is_streaming_and_bounded(): void
    {
        // Create 2MB file (large relative to low memory limit)
        $fileName = 'streamed-file.png';
        $filePath = $this->mediaRoot.DIRECTORY_SEPARATOR.$fileName;
        $chunk = str_repeat('X', 65536);
        $fh = fopen($filePath, 'wb');
        for ($i = 0; $i < 32; $i++) {
            fwrite($fh, $chunk);
        }
        fclose($fh);

        $expectedSha = hash_file('sha256', $filePath);

        SeoMedia::query()->create([
            'site_id' => $this->site->id,
            'filename' => $fileName,
            'slug' => 'streamed-file',
            'path' => 'uploads/seo_media/'.$fileName,
            'url' => '/storage/uploads/seo_media/'.$fileName,
            'source' => 'upload',
        ]);

        $memBefore = memory_get_usage(true);

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_streamed.zip';
        $exporter = new ClientTransferExporter;
        $exporter->export($zipPath);

        $memAfter = memory_get_usage(true);

        // Memory delta must not spike by multiple whole file copies
        self::assertLessThan(16 * 1024 * 1024, $memAfter - $memBefore, 'Memory usage must remain bounded.');

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $stat = $zip->statName("media/files/{$expectedSha}.png");
        $zip->close();

        self::assertNotFalse($stat);
        self::assertSame(2 * 1024 * 1024, $stat['size']);
    }

    public function test_missing_db_linked_physical_file_produces_warning_not_silent_skip(): void
    {
        // DB record points to file that does NOT exist
        SeoMedia::query()->create([
            'site_id' => $this->site->id,
            'filename' => 'ghost-file.jpg',
            'slug' => 'ghost-file',
            'path' => 'uploads/seo_media/ghost-file.jpg',
            'url' => '/storage/uploads/seo_media/ghost-file.jpg',
            'source' => 'upload',
        ]);

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_missing.zip';
        $exporter = new ClientTransferExporter;
        $result = $exporter->export($zipPath);

        // Record is still counted and exported in NDJSON
        self::assertSame(1, $result['counts']['media']);

        $manifest = $result['manifest'];
        self::assertSame(1, $manifest->media['stats']['missing_media_files']);

        $warnings = $manifest->media['warnings'];
        self::assertNotEmpty($warnings);
        self::assertSame('MEDIA_FILE_MISSING', $warnings[0]['code']);
        self::assertSame('uploads/seo_media/ghost-file.jpg', $warnings[0]['path']);
    }

    public function test_orphan_file_inside_managed_media_storage_is_exported(): void
    {
        // Physical file in uploads/seo_media without any DB row
        $orphanData = 'orphan-durable-media-binary';
        $sha = hash('sha256', $orphanData);
        $fileName = 'historical-orphan.webp';
        file_put_contents($this->mediaRoot.DIRECTORY_SEPARATOR.$fileName, $orphanData);

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_orphan.zip';
        $exporter = new ClientTransferExporter;
        $result = $exporter->export($zipPath);

        $manifest = $result['manifest'];
        self::assertSame(1, $manifest->media['stats']['orphan_files']);

        // Check ZIP contains the physical file
        $zip = new ZipArchive;
        $zip->open($zipPath);
        $fromZip = $zip->getFromName("media/files/{$sha}.webp");
        $orphanNdjson = $zip->getFromName('media/orphan_files.ndjson');
        $zip->close();

        self::assertSame($orphanData, $fromZip);
        self::assertNotFalse($orphanNdjson);
        $orphanDecoded = json_decode(trim($orphanNdjson), true);
        self::assertSame("sha256:{$sha}", $orphanDecoded['file_ref']);
        self::assertSame("uploads/seo_media/{$fileName}", $orphanDecoded['managed_relative_path']);
        self::assertSame($fileName, $orphanDecoded['filename']);
    }

    public function test_unrelated_arbitrary_storage_file_is_not_exported(): void
    {
        // File in uploads/team-chat/
        $chatDir = dirname($this->mediaRoot).DIRECTORY_SEPARATOR.'team-chat';
        mkdir($chatDir, 0755, true);
        file_put_contents($chatDir.DIRECTORY_SEPARATOR.'private_chat.png', 'secret-chat-image');

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_unrelated.zip';
        $exporter = new ClientTransferExporter;
        $result = $exporter->export($zipPath);

        $zip = new ZipArchive;
        $zip->open($zipPath);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            self::assertStringNotContainsString('private_chat', $name);
        }
        $zip->close();

        self::assertSame(0, $result['manifest']->media['stats']['orphan_files']);
    }

    public function test_temp_cache_derived_files_are_excluded_according_to_media_ownership_rules(): void
    {
        // Put .gitkeep and .tmp files in uploads/seo_media
        file_put_contents($this->mediaRoot.DIRECTORY_SEPARATOR.'.gitkeep', '');
        file_put_contents($this->mediaRoot.DIRECTORY_SEPARATOR.'scratch.tmp', 'temp-scratch-data');
        file_put_contents($this->mediaRoot.DIRECTORY_SEPARATOR.'partial.part', 'partial-upload');
        file_put_contents($this->mediaRoot.DIRECTORY_SEPARATOR.'placeholder-loading.svg', '<svg></svg>');

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_temp.zip';
        $exporter = new ClientTransferExporter;
        $result = $exporter->export($zipPath);

        // None of these should be exported as orphans or files
        self::assertSame(0, $result['manifest']->media['stats']['orphan_files']);
        self::assertSame(0, $result['manifest']->media['stats']['media_files']);

        $zip = new ZipArchive;
        $zip->open($zipPath);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            self::assertFalse(str_starts_with($name, 'media/files/'));
        }
        $zip->close();
    }

    public function test_absolute_local_paths_are_not_written_as_portable_identity(): void
    {
        $content = 'path-test-content';
        $fileName = 'local-path-test.png';
        file_put_contents($this->mediaRoot.DIRECTORY_SEPARATOR.$fileName, $content);

        // Provide a Windows absolute path in DB record
        $windowsPath = 'D:\\work\\storage\\public\\uploads\\seo_media\\'.$fileName;
        SeoMedia::query()->create([
            'site_id' => $this->site->id,
            'filename' => $fileName,
            'slug' => 'local-path-test',
            'path' => $windowsPath,
            'url' => 'file:///D:/work/storage/public/uploads/seo_media/'.$fileName,
            'source' => 'upload',
        ]);

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_safe_path.zip';
        $exporter = new ClientTransferExporter;
        $exporter->export($zipPath);

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $recordPart = $zip->getFromName('media/records/part-000001.ndjson');
        $zip->close();

        self::assertNotFalse($recordPart);
        $record = json_decode(trim($recordPart), true);

        // Assert path does not contain Windows drive letter
        self::assertStringNotContainsString('D:', $record['path']);
        self::assertStringNotContainsString('\\', $record['path']);
        self::assertSame('uploads/seo_media/'.$fileName, $record['path']);

        // Assert url does not contain file:/// or Windows drive letter
        self::assertStringNotContainsString('file://', $record['url']);
        self::assertStringNotContainsString('D:', $record['url']);
    }

    public function test_path_traversal_and_symlink_escape_is_rejected(): void
    {
        $escapeDir = $this->tempDir.DIRECTORY_SEPARATOR.'escape_target';
        mkdir($escapeDir, 0755, true);
        $targetFile = $escapeDir.DIRECTORY_SEPARATOR.'secret_escape.txt';
        file_put_contents($targetFile, 'super_secret');

        // DB record attempts path traversal
        SeoMedia::query()->create([
            'site_id' => $this->site->id,
            'filename' => 'traversal.png',
            'slug' => 'traversal',
            'path' => 'uploads/seo_media/../../secret_escape.txt',
            'url' => '/storage/secret_escape.txt',
            'source' => 'upload',
        ]);

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_escape.zip';
        $exporter = new ClientTransferExporter;
        $result = $exporter->export($zipPath);

        $manifest = $result['manifest'];
        self::assertSame(0, $manifest->media['stats']['media_files']);

        $warnings = $manifest->media['warnings'];
        self::assertNotEmpty($warnings);
        self::assertContains($warnings[0]['code'], ['MEDIA_PATH_TRAVERSAL', 'MEDIA_FILE_CONTAINMENT_VIOLATION']);
    }

    public function test_wordpress_original_media_is_not_downloaded_or_bundled(): void
    {
        // WordPress original media record pointing to external URL
        SeoMedia::query()->create([
            'site_id' => $this->site->id,
            'filename' => 'wp-original-logo.png',
            'slug' => 'wp-original-logo',
            'path' => '',
            'url' => 'https://external-wordpress-site.example/wp-content/uploads/wp-original-logo.png',
            'source' => 'wordpress',
            'wp_attachment_id' => 888,
        ]);

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_wp_out_of_scope.zip';
        $exporter = new ClientTransferExporter;
        $result = $exporter->export($zipPath);

        // Record is exported in NDJSON
        self::assertSame(1, $result['counts']['media']);

        // But no files downloaded or bundled in media/files
        self::assertSame(0, $result['manifest']->media['stats']['media_files']);

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $recordPart = $zip->getFromName('media/records/part-000001.ndjson');
        $zip->close();

        self::assertNotFalse($recordPart);
        $record = json_decode(trim($recordPart), true);
        self::assertNull($record['file_ref']);
        self::assertSame('https://external-wordpress-site.example/wp-content/uploads/wp-original-logo.png', $record['url']);
    }

    public function test_existing_client_transfer_import_tests_remain_green(): void
    {
        // Create full export package with media binary
        $content = 'import-compat-binary';
        $sha = hash('sha256', $content);
        $fileName = 'compat.png';
        file_put_contents($this->mediaRoot.DIRECTORY_SEPARATOR.$fileName, $content);

        $media = SeoMedia::query()->create([
            'site_id' => $this->site->id,
            'filename' => $fileName,
            'slug' => 'compat',
            'path' => 'uploads/seo_media/'.$fileName,
            'url' => '/storage/uploads/seo_media/'.$fileName,
            'source' => 'upload',
            'alt_text' => 'Compat Alt',
        ]);

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_compat.zip';
        $exporter = new ClientTransferExporter;
        $exporter->export($zipPath);

        // Wipe DB
        $this->wipeBusinessTables();
        self::assertSame(0, SeoMedia::query()->count());

        // Import the package
        $importer = new ClientTransferImporter;
        $importResult = $importer->import($zipPath);

        self::assertSame(0, $importResult['total_failed']);
        self::assertSame(1, SeoMedia::query()->count());

        $importedMedia = SeoMedia::query()->firstOrFail();
        self::assertSame('compat', $importedMedia->slug);
        self::assertSame('Compat Alt', $importedMedia->alt_text);
        self::assertSame('uploads/seo_media/'.$fileName, $importedMedia->path);
    }

    public function test_no_media_binary_import_or_restore_behavior_was_added(): void
    {
        // Prove that during import, no file is written to target storage
        $content = 'no-restore-binary-check';
        $sha = hash('sha256', $content);
        $fileName = 'no-restore.png';
        $targetFile = $this->mediaRoot.DIRECTORY_SEPARATOR.$fileName;
        file_put_contents($targetFile, $content);

        SeoMedia::query()->create([
            'site_id' => $this->site->id,
            'filename' => $fileName,
            'slug' => 'no-restore',
            'path' => 'uploads/seo_media/'.$fileName,
            'url' => '/storage/uploads/seo_media/'.$fileName,
            'source' => 'upload',
        ]);

        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_no_restore.zip';
        $exporter = new ClientTransferExporter;
        $exporter->export($zipPath);

        // Delete the physical file from disk and DB
        @unlink($targetFile);
        self::assertFileDoesNotExist($targetFile);
        $this->wipeBusinessTables();

        // Run import
        $importer = new ClientTransferImporter;
        $importer->import($zipPath);

        // DB record is created, BUT physical file MUST NOT be restored by import (export-only scope)
        self::assertSame(1, SeoMedia::query()->count());
        self::assertFileDoesNotExist($targetFile, 'Import MUST NOT restore or write media binaries in this scope.');
    }

    public function test_client_transfer_export_artifacts_are_outside_media_discovery(): void
    {
        // 1. Create dummy export / staging / quarantine artifacts in storage
        $publicRoot = config('filesystems.disks.public.root');
        $transferArtifactsDir = $publicRoot.DIRECTORY_SEPARATOR.'client-transfer'.DIRECTORY_SEPARATOR.'exports';
        mkdir($transferArtifactsDir, 0755, true);
        file_put_contents($transferArtifactsDir.DIRECTORY_SEPARATOR.'seo-export-latest.zip', 'dummy-zip-bytes');

        $quarantineDir = $publicRoot.DIRECTORY_SEPARATOR.'client-transfer'.DIRECTORY_SEPARATOR.'quarantine';
        mkdir($quarantineDir, 0755, true);
        file_put_contents($quarantineDir.DIRECTORY_SEPARATOR.'retry.zip', 'dummy-quarantine-bytes');

        $stagingDir = $publicRoot.DIRECTORY_SEPARATOR.'client-transfer'.DIRECTORY_SEPARATOR.'staging_export_test';
        mkdir($stagingDir, 0755, true);
        file_put_contents($stagingDir.DIRECTORY_SEPARATOR.'some_image.png', 'fake-staging-image');

        // Also test that generic storage roots are rejected by isApprovedManagedRoot
        self::assertFalse(MediaBinaryManager::isApprovedManagedRoot('storage'));
        self::assertFalse(MediaBinaryManager::isApprovedManagedRoot('storage/app'));
        self::assertFalse(MediaBinaryManager::isApprovedManagedRoot('public'));
        self::assertFalse(MediaBinaryManager::isApprovedManagedRoot('client-transfer'));
        self::assertTrue(MediaBinaryManager::isApprovedManagedRoot('uploads/seo_media'));

        // Run full export
        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'export_regression_check.zip';
        $exporter = new ClientTransferExporter;
        $result = $exporter->export($zipPath);

        // Assert 0 orphan files discovered and 0 media files stored from client-transfer artifacts
        $manifest = $result['manifest'];
        self::assertSame(0, $manifest->media['stats']['orphan_files']);
        self::assertSame(0, $manifest->media['stats']['media_files']);

        $zip = new ZipArchive;
        $zip->open($zipPath);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            self::assertStringNotContainsString('client-transfer', $name);
            self::assertStringNotContainsString('seo-export-latest', $name);
            self::assertStringNotContainsString('retry.zip', $name);
        }
        $zip->close();
    }
}

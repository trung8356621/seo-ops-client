<?php

declare(strict_types=1);

namespace Tests\Feature\ClientTransfer;

use App\Jobs\ClientTransfer\ImportMediaOrphansSliceJob;
use App\Jobs\ClientTransfer\RollbackSeoImportJob;
use App\Models\ClientTransferRun;
use App\Models\Site;
use App\Models\User;
use App\Services\ClientTransfer\ClientTransferExporter;
use App\Services\ClientTransfer\ClientTransferImporter;
use App\Services\ClientTransfer\DatasetRegistry;
use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\MediaBinaryManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use App\Services\ClientTransfer\Support\ZipArchiveManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Tests\Unit\ClientTransfer\TransferDatabaseTestCase;
use ZipArchive;

final class MediaBinaryImportTest extends TransferDatabaseTestCase
{
    private User $user;

    private Site $site;

    private string $mediaRoot;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::forgetDisk('public');

        $this->user = User::query()->create([
            'name' => 'Media Import Admin',
            'email' => 'admin@media-import.test',
            'role' => 'admin',
        ]);

        $this->site = Site::query()->create([
            'domain' => 'media-import.test',
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

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

    public function test_exported_laravel_binary_imports_byte_for_byte(): void
    {
        $bytes = random_bytes(2048);
        $this->seedMediaFile('exact.bin.png', 'exact-bin', $bytes);
        $zipPath = $this->exportThenWipeFilesAndDb('import_bytes.zip');

        $result = (new ClientTransferImporter)->import($zipPath);

        self::assertSame(0, $result['total_failed']);
        $imported = SeoMedia::query()->firstOrFail();
        self::assertFileExists(Storage::disk('public')->path($imported->path));
        self::assertSame($bytes, file_get_contents(Storage::disk('public')->path($imported->path)));
        self::assertSame(hash('sha256', $bytes), hash_file('sha256', Storage::disk('public')->path($imported->path)));
    }

    public function test_sha256_is_verified_on_import(): void
    {
        $content = 'checksum-ok-payload';
        $this->seedMediaFile('checksum.png', 'checksum', $content);
        $zipPath = $this->exportThenWipeFilesAndDb('import_checksum.zip');

        (new ClientTransferImporter)->import($zipPath);

        $imported = SeoMedia::query()->firstOrFail();
        self::assertSame(hash('sha256', $content), hash_file('sha256', Storage::disk('public')->path($imported->path)));
    }

    public function test_duplicate_file_ref_writes_one_physical_binary(): void
    {
        $shared = 'shared-import-bytes';
        $this->seedMediaFile('dup-one.jpg', 'dup-one', $shared);
        $this->seedMediaFile('dup-two.jpg', 'dup-two', $shared);
        $zipPath = $this->exportThenWipeFilesAndDb('import_dedup.zip');

        (new ClientTransferImporter)->import($zipPath);

        self::assertSame(2, SeoMedia::query()->count());
        $paths = SeoMedia::query()->pluck('path')->unique()->all();
        self::assertCount(1, $paths);
        $files = array_values(array_filter(scandir($this->mediaRoot) ?: [], fn (string $f): bool => ! in_array($f, ['.', '..'], true) && ! str_starts_with($f, '.')));
        self::assertCount(1, $files);
        self::assertSame($shared, file_get_contents($this->mediaRoot.DIRECTORY_SEPARATOR.$files[0]));
    }

    public function test_media_with_null_article_relation_imports(): void
    {
        $media = $this->seedMediaFile('standalone.jpg', 'standalone', 'no-article');
        $media->setAttribute('article_id', null);
        $media->save();
        $zipPath = $this->exportThenWipeFilesAndDb('import_null_article.zip');

        $result = (new ClientTransferImporter)->import($zipPath);

        self::assertSame(0, $result['total_failed']);
        $imported = SeoMedia::query()->firstOrFail();
        self::assertNull($imported->getAttribute('article_id'));
        self::assertFileExists(Storage::disk('public')->path($imported->path));
    }

    public function test_source_absolute_path_is_never_reused(): void
    {
        $fileName = 'windows-src.png';
        file_put_contents($this->mediaRoot.DIRECTORY_SEPARATOR.$fileName, 'win-bytes');
        SeoMedia::query()->create([
            'site_id' => $this->site->id,
            'filename' => $fileName,
            'slug' => 'windows-src',
            'path' => 'D:\\old\\uploads\\seo_media\\'.$fileName,
            'url' => 'file:///D:/old/uploads/seo_media/'.$fileName,
            'source' => 'upload',
        ]);
        $zipPath = $this->exportThenWipeFilesAndDb('import_windows.zip');

        (new ClientTransferImporter)->import($zipPath);

        $imported = SeoMedia::query()->firstOrFail();
        self::assertStringNotContainsString('D:', $imported->path);
        self::assertStringNotContainsString('\\', $imported->path);
        self::assertStringStartsWith('uploads/seo_media/', $imported->path);
        self::assertStringStartsWith('/storage/uploads/seo_media/', $imported->url);
    }

    public function test_target_media_allocator_path_semantics_are_used(): void
    {
        $this->seedMediaFile('original-name.png', 'allocated-slug', 'allocator-bytes');
        $zipPath = $this->exportThenWipeFilesAndDb('import_allocator.zip');
        file_put_contents($this->mediaRoot.DIRECTORY_SEPARATOR.'allocated-slug.png', 'different-existing');

        (new ClientTransferImporter)->import($zipPath);

        $imported = SeoMedia::query()->firstOrFail();
        self::assertSame('allocated-slug', $imported->slug);
        self::assertSame('original-name.png', $imported->filename);
        self::assertSame('uploads/seo_media/allocated-slug-1.png', $imported->path);
        self::assertSame('allocator-bytes', file_get_contents(Storage::disk('public')->path($imported->path)));
        self::assertSame('different-existing', file_get_contents($this->mediaRoot.DIRECTORY_SEPARATOR.'allocated-slug.png'));
    }

    public function test_orphan_file_restores_without_creating_fake_db_media_row(): void
    {
        file_put_contents($this->mediaRoot.DIRECTORY_SEPARATOR.'historical-orphan.webp', 'orphan-bytes');
        $zipPath = $this->exportThenWipeFilesAndDb('import_orphan.zip');

        (new ClientTransferImporter)->import($zipPath);

        self::assertSame(0, SeoMedia::query()->count());
        self::assertFileExists($this->mediaRoot.DIRECTORY_SEPARATOR.'historical-orphan.webp');
        self::assertSame('orphan-bytes', file_get_contents($this->mediaRoot.DIRECTORY_SEPARATOR.'historical-orphan.webp'));
    }

    public function test_missing_package_binary_produces_explicit_failure(): void
    {
        $this->seedMediaFile('missing.png', 'missing', 'present-then-removed');
        $zipPath = $this->exportThenWipeFilesAndDb('import_missing.zip');
        $sha = hash('sha256', 'present-then-removed');
        $zip = new ZipArchive;
        self::assertTrue($zip->open($zipPath));
        $zip->deleteName("media/files/{$sha}.png");
        $zip->close();

        $result = (new ClientTransferImporter)->import($zipPath);

        self::assertGreaterThan(0, $result['total_failed']);
        self::assertSame(0, SeoMedia::query()->count());
        $logs = (new ImportRun($result['run_id']))->getLogs();
        $codes = array_column($logs, 'errorType');
        self::assertContains('MEDIA_BINARY_MISSING', $codes);
    }

    public function test_checksum_mismatch_is_rejected(): void
    {
        $this->seedMediaFile('tamper.png', 'tamper', 'original-bytes');
        $zipPath = $this->exportThenWipeFilesAndDb('import_tamper.zip');
        $sha = hash('sha256', 'original-bytes');
        $zip = new ZipArchive;
        self::assertTrue($zip->open($zipPath));
        $zip->deleteName("media/files/{$sha}.png");
        $zip->addFromString("media/files/{$sha}.png", 'tampered-bytes');
        $zip->close();

        $result = (new ClientTransferImporter)->import($zipPath);

        self::assertGreaterThan(0, $result['total_failed']);
        self::assertSame(0, SeoMedia::query()->count());
        $logs = (new ImportRun($result['run_id']))->getLogs();
        self::assertContains('MEDIA_CHECKSUM_MISMATCH', array_column($logs, 'errorType'));
        self::assertFileDoesNotExist($this->mediaRoot.DIRECTORY_SEPARATOR.'tamper.png');
        foreach (scandir($this->mediaRoot) ?: [] as $entry) {
            self::assertStringNotContainsString('tampered', (string) (@file_get_contents($this->mediaRoot.DIRECTORY_SEPARATOR.$entry) ?: ''));
        }
    }

    public function test_traversal_path_escape_is_rejected(): void
    {
        $staging = $this->tempDir.DIRECTORY_SEPARATOR.'orphan_staging';
        mkdir($staging.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'files', 0755, true);
        $payload = 'escaped';
        $hash = hash('sha256', $payload);
        file_put_contents($staging.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'files'.DIRECTORY_SEPARATOR.$hash.'.png', $payload);
        $manager = new MediaBinaryManager($staging);
        $refMap = new ReferenceMap('trav-'.bin2hex(random_bytes(4)));
        $result = $manager->restoreOrphanRecord([
            'file_ref' => 'sha256:'.$hash,
            'managed_relative_path' => 'uploads/seo_media/../../secret.png',
            'filename' => 'secret.png',
            'extension' => 'png',
        ], $refMap);

        self::assertFalse($result['ok']);
        self::assertSame('MEDIA_PATH_TRAVERSAL', $result['code']);
        self::assertFileDoesNotExist(dirname($this->mediaRoot).DIRECTORY_SEPARATOR.'secret.png');
    }

    public function test_large_binary_handling_is_streaming_and_bounded(): void
    {
        $filePath = $this->mediaRoot.DIRECTORY_SEPARATOR.'streamed-import.png';
        $chunk = str_repeat('Y', 65536);
        $fh = fopen($filePath, 'wb');
        for ($i = 0; $i < 32; $i++) {
            fwrite($fh, $chunk);
        }
        fclose($fh);
        SeoMedia::query()->create([
            'site_id' => $this->site->id,
            'filename' => 'streamed-import.png',
            'slug' => 'streamed-import',
            'path' => 'uploads/seo_media/streamed-import.png',
            'url' => '/storage/uploads/seo_media/streamed-import.png',
            'source' => 'upload',
        ]);
        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'import_stream.zip';
        (new ClientTransferExporter)->export($zipPath);
        @unlink($filePath);
        $this->wipeBusinessTables();

        $memBefore = memory_get_usage(true);
        (new ClientTransferImporter)->import($zipPath);
        $memAfter = memory_get_usage(true);

        self::assertLessThan(16 * 1024 * 1024, $memAfter - $memBefore);
        $imported = SeoMedia::query()->firstOrFail();
        self::assertSame(2 * 1024 * 1024, filesize(Storage::disk('public')->path($imported->path)));
    }

    public function test_rollback_deletes_files_created_by_this_import(): void
    {
        $this->seedMediaFile('created.png', 'created', 'created-bytes');
        $zipPath = $this->exportThenWipeFilesAndDb('rollback_created.zip');
        $runId = 'rb-created-'.bin2hex(random_bytes(4));
        $this->importThroughJournaledRun($runId, $zipPath);

        $imported = SeoMedia::query()->firstOrFail();
        $relative = $imported->path;
        self::assertFileExists(Storage::disk('public')->path($relative));

        $run = ClientTransferRun::query()->where('run_id', $runId)->firstOrFail();
        $run->update(['status' => 'completed']);
        (new RollbackSeoImportJob($runId))->handle(new DatasetRegistry);

        self::assertSame(0, SeoMedia::query()->count());
        self::assertFileDoesNotExist(Storage::disk('public')->path($relative));
    }

    public function test_rollback_does_not_delete_pre_existing_reused_files(): void
    {
        $content = 'reuse-bytes';
        $this->seedMediaFile('reuse.png', 'reuse', $content);
        $zipPath = $this->exportThenWipeFilesAndDb('rollback_reuse.zip');
        $preexisting = $this->mediaRoot.DIRECTORY_SEPARATOR.'reuse.png';
        file_put_contents($preexisting, $content);

        $runId = 'rb-reuse-'.bin2hex(random_bytes(4));
        $this->importThroughJournaledRun($runId, $zipPath);

        $run = ClientTransferRun::query()->where('run_id', $runId)->firstOrFail();
        $run->update(['status' => 'completed']);
        (new RollbackSeoImportJob($runId))->handle(new DatasetRegistry);

        self::assertSame(0, SeoMedia::query()->count());
        self::assertFileExists($preexisting);
        self::assertSame($content, file_get_contents($preexisting));
    }

    public function test_cancellation_prevents_orphan_continuation(): void
    {
        Queue::fake();
        $run = ClientTransferRun::query()->create([
            'run_id' => 'cancel-orphan-'.bin2hex(random_bytes(4)),
            'type' => 'import',
            'status' => 'cancelled',
            'phase' => 'cancelled',
            'finished_at' => now(),
        ]);

        (new ImportMediaOrphansSliceJob($run->run_id, 0))->handle();
        Queue::assertNothingPushed();
        self::assertSame('cancelled', $run->fresh()->status);
    }

    public function test_wordpress_original_binary_is_untouched(): void
    {
        Http::fake();
        SeoMedia::query()->create([
            'site_id' => $this->site->id,
            'filename' => 'wp-original-logo.png',
            'slug' => 'wp-original-logo',
            'path' => '',
            'url' => 'https://external-wordpress-site.example/wp-content/uploads/wp-original-logo.png',
            'source' => 'wordpress',
            'wp_attachment_id' => 888,
        ]);
        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.'import_wp.zip';
        (new ClientTransferExporter)->export($zipPath);
        $this->wipeBusinessTables();

        (new ClientTransferImporter)->import($zipPath);

        Http::assertNothingSent();
        $imported = SeoMedia::query()->firstOrFail();
        self::assertSame('wordpress', $imported->source);
        self::assertSame('https://external-wordpress-site.example/wp-content/uploads/wp-original-logo.png', $imported->url);
        self::assertSame('', $imported->path);
        $files = array_values(array_filter(scandir($this->mediaRoot) ?: [], fn (string $f): bool => ! in_array($f, ['.', '..'], true)));
        self::assertSame([], $files);
    }

    private function seedMediaFile(string $fileName, string $slug, string $content): SeoMedia
    {
        file_put_contents($this->mediaRoot.DIRECTORY_SEPARATOR.$fileName, $content);

        return SeoMedia::query()->create([
            'site_id' => $this->site->id,
            'filename' => $fileName,
            'slug' => $slug,
            'path' => 'uploads/seo_media/'.$fileName,
            'url' => '/storage/uploads/seo_media/'.$fileName,
            'source' => 'upload',
            'status' => 'ready',
        ]);
    }

    private function exportThenWipeFilesAndDb(string $zipName): string
    {
        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.$zipName;
        (new ClientTransferExporter)->export($zipPath);
        foreach (scandir($this->mediaRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            @unlink($this->mediaRoot.DIRECTORY_SEPARATOR.$entry);
        }
        $this->wipeBusinessTables();

        return $zipPath;
    }

    private function importThroughJournaledRun(string $runId, string $zipPath): void
    {
        ClientTransferRun::query()->create([
            'run_id' => $runId,
            'type' => 'import',
            'status' => 'running',
            'phase' => 'import:media',
        ]);
        $stagingDir = storage_path("app/client-transfer/staging_import_{$runId}");
        ZipArchiveManager::extractAndValidate($zipPath, $stagingDir);
        $refMap = new ReferenceMap($runId);
        $importRun = new ImportRun($runId, $refMap);
        $blobs = new BlobManager($stagingDir.DIRECTORY_SEPARATOR.'content'.DIRECTORY_SEPARATOR.'blobs');
        $dataset = (new DatasetRegistry)->get('media');
        $part = $stagingDir.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'records'.DIRECTORY_SEPARATOR.'part-000001.ndjson';
        if (is_file($part)) {
            foreach (file($part) ?: [] as $index => $line) {
                $record = json_decode(trim($line), true);
                if (! is_array($record)) {
                    continue;
                }
                $dataset->importRecord($record, $refMap, $importRun, $blobs, 'media/records/part-000001.ndjson', $index);
            }
        }
        $dataset->importOrphanFiles($refMap, $importRun, $blobs);
    }
}

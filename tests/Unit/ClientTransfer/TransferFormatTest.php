<?php

declare(strict_types=1);

namespace Tests\Unit\ClientTransfer;

use App\Services\ClientTransfer\DatasetRegistry;
use App\Services\ClientTransfer\Exceptions\BlobChecksumMismatchException;
use App\Services\ClientTransfer\Exceptions\DependencyCycleException;
use App\Services\ClientTransfer\Exceptions\FatalImportException;
use App\Services\ClientTransfer\Exceptions\UnsupportedFormatVersionException;
use App\Services\ClientTransfer\Manifest\DatasetManifest;
use App\Services\ClientTransfer\Manifest\PartManifest;
use App\Services\ClientTransfer\Manifest\TransferManifest;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use App\Services\ClientTransfer\Support\TopologicalSorter;
use App\Services\ClientTransfer\Support\ZipArchiveManager;
use Tests\TestCase;

final class TransferFormatTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'transfer_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_manifest_validation_succeeds_for_current_version(): void
    {
        $manifest = new TransferManifest(
            format: 'seo-ops-transfer',
            formatVersion: 1,
            exportedAt: date('c'),
            source: ['app_version' => '1.0.0'],
            datasets: [
                'users' => new DatasetManifest('users', 2, [], [
                    new PartManifest('core/users/part-000001.ndjson', 2, hash('sha256', 'test'), 4),
                ]),
            ],
        );

        $json = $manifest->toJson();
        $restored = TransferManifest::fromJson($json);

        self::assertSame('seo-ops-transfer', $restored->format);
        self::assertSame(1, $restored->formatVersion);
        self::assertArrayHasKey('users', $restored->datasets);
        self::assertSame(2, $restored->datasets['users']->count);
    }

    public function test_manifest_validation_fails_for_unsupported_format_or_version(): void
    {
        $this->expectException(UnsupportedFormatVersionException::class);

        (new TransferManifest(
            format: 'unknown-format',
            formatVersion: 1,
            exportedAt: date('c'),
            source: [],
            datasets: [],
        ))->validate();
    }

    public function test_manifest_validation_fails_for_future_version(): void
    {
        $this->expectException(UnsupportedFormatVersionException::class);

        (new TransferManifest(
            format: 'seo-ops-transfer',
            formatVersion: 999,
            exportedAt: date('c'),
            source: [],
            datasets: [],
        ))->validate();
    }

    public function test_topological_sorter_resolves_dependency_order(): void
    {
        $graph = [
            'articles' => ['sites', 'users'],
            'sites' => ['users'],
            'users' => [],
            'keywords' => [],
            'topics' => ['sites'],
            'topic_keywords' => ['topics', 'keywords', 'sites'],
        ];

        $sorted = TopologicalSorter::sort($graph);

        // users must come before sites, sites before articles and topics, etc.
        $userPos = array_search('users', $sorted, true);
        $sitePos = array_search('sites', $sorted, true);
        $articlePos = array_search('articles', $sorted, true);
        $topicPos = array_search('topics', $sorted, true);
        $topicKwPos = array_search('topic_keywords', $sorted, true);

        self::assertLessThan($sitePos, $userPos);
        self::assertLessThan($articlePos, $sitePos);
        self::assertLessThan($topicPos, $sitePos);
        self::assertLessThan($topicKwPos, $topicPos);
    }

    public function test_topological_sorter_detects_dependency_cycle(): void
    {
        $this->expectException(DependencyCycleException::class);

        $cycleGraph = [
            'A' => ['B'],
            'B' => ['C'],
            'C' => ['A'],
        ];

        TopologicalSorter::sort($cycleGraph);
    }

    public function test_reference_map_sets_gets_and_tracks_deferred(): void
    {
        $runId = 'test_run_' . bin2hex(random_bytes(4));
        $dbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'test_refmap.sqlite';
        $refMap = new ReferenceMap($runId, $dbPath);

        $refMap->set('user:10', 'user', 101);
        $refMap->set('site:5', 'site', 501);
        $refMap->setMany([
            ['source_ref' => 'keyword:1', 'entity_type' => 'keyword', 'target_id' => 1001],
            ['source_ref' => 'keyword:2', 'entity_type' => 'keyword', 'target_id' => 1002],
        ]);

        self::assertSame(101, $refMap->get('user:10'));
        self::assertSame(501, $refMap->get('site:5'));
        self::assertSame(1001, $refMap->get('keyword:1'));
        self::assertNull($refMap->get('nonexistent:ref'));

        $batch = $refMap->getMany(['keyword:1', 'keyword:2', 'missing:9']);
        self::assertSame(1001, $batch['keyword:1']);
        self::assertSame(1002, $batch['keyword:2']);
        self::assertArrayNotHasKey('missing:9', $batch);

        // Deferred refs
        $refMap->addDeferred('users', 101, 'parent_id', 'user:99');
        self::assertSame(1, $refMap->countDeferred());
        $deferred = $refMap->getDeferredReferences();
        self::assertSame('parent_id', $deferred[0]['field_name']);
        self::assertSame('user:99', $deferred[0]['target_ref']);

        $refMap->cleanup();
        self::assertFileDoesNotExist($dbPath);
    }

    public function test_blob_manager_stores_and_verifies_checksum(): void
    {
        $blobDir = $this->tempDir . DIRECTORY_SEPARATOR . 'blobs';
        $blobs = new BlobManager($blobDir);

        $content = '<h1>Fidelity Test</h1><p>Sample content</p>';
        $stored = $blobs->store($content, 'html');

        self::assertSame(hash('sha256', $content), $stored['sha256']);
        self::assertFileExists($blobDir . DIRECTORY_SEPARATOR . $stored['sha256'] . '.html');

        // Reading with correct hash succeeds
        $fullPath = $blobDir . DIRECTORY_SEPARATOR . $stored['sha256'] . '.html';
        $read = $blobs->readAndVerify($fullPath, $stored['sha256'], 'articles', 'article:1', 'body');
        self::assertSame($content, $read);

        // Reading with wrong hash throws BlobChecksumMismatchException
        $this->expectException(BlobChecksumMismatchException::class);
        $blobs->readAndVerify($fullPath, 'tampered_wrong_hash', 'articles', 'article:1', 'body');
    }

    public function test_zip_archive_manager_validates_part_checksums_and_detects_tampering(): void
    {
        $staging = $this->tempDir . DIRECTORY_SEPARATOR . 'staging';
        mkdir($staging . '/seo/keywords', 0755, true);

        $keywordFile = $staging . '/seo/keywords/part-000001.ndjson';
        file_put_contents($keywordFile, "{\"ref\":\"keyword:1\",\"phrase\":\"test\"}\n");
        $sha = hash_file('sha256', $keywordFile);

        $manifest = new TransferManifest(
            format: 'seo-ops-transfer',
            formatVersion: 1,
            exportedAt: date('c'),
            source: [],
            datasets: [
                'keywords' => new DatasetManifest('keywords', 1, [], [
                    new PartManifest('seo/keywords/part-000001.ndjson', 1, $sha, (int) filesize($keywordFile)),
                ]),
            ],
        );
        file_put_contents($staging . '/manifest.json', $manifest->toJson());

        $zipPath = $this->tempDir . DIRECTORY_SEPARATOR . 'test.zip';
        ZipArchiveManager::create($staging, $zipPath);
        self::assertFileExists($zipPath);

        // Valid extract succeeds
        $dest = $this->tempDir . DIRECTORY_SEPARATOR . 'extracted';
        $res = ZipArchiveManager::extractAndValidate($zipPath, $dest);
        self::assertInstanceOf(TransferManifest::class, $res['manifest']);

        // Now tamper with the zip by replacing keyword file with different content
        $tamperedStaging = $this->tempDir . DIRECTORY_SEPARATOR . 'tampered';
        mkdir($tamperedStaging . '/seo/keywords', 0755, true);
        file_put_contents($tamperedStaging . '/seo/keywords/part-000001.ndjson', "{\"ref\":\"keyword:1\",\"phrase\":\"TAMPERED\"}\n");
        file_put_contents($tamperedStaging . '/manifest.json', $manifest->toJson()); // Keep original sha in manifest

        $tamperedZip = $this->tempDir . DIRECTORY_SEPARATOR . 'tampered.zip';
        ZipArchiveManager::create($tamperedStaging, $tamperedZip);

        $this->expectException(FatalImportException::class);
        ZipArchiveManager::extractAndValidate($tamperedZip, $this->tempDir . DIRECTORY_SEPARATOR . 'tampered_extracted');
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = scandir($dir);
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $p = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($p) ? $this->deleteDirectory($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}

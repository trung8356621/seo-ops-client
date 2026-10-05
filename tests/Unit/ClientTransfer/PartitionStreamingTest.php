<?php

declare(strict_types=1);

namespace Tests\Unit\ClientTransfer;

use App\Services\ClientTransfer\Datasets\ArticlesDataset;
use App\Services\ClientTransfer\Datasets\KeywordsDataset;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\NdjsonPartReader;
use App\Services\ClientTransfer\Support\NdjsonPartWriter;
use Tests\TestCase;

final class PartitionStreamingTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'partition_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_ndjson_writer_splits_parts_on_max_records(): void
    {
        $dir = $this->tempDir . '/parts_by_count';
        // Max 5 records per part
        $writer = new NdjsonPartWriter($dir, 'seo/keywords', maxRecords: 5, maxBytes: 1048576);

        for ($i = 1; $i <= 12; $i++) {
            $writer->writeRecord(['ref' => "keyword:{$i}", 'phrase' => "Keyword {$i}"]);
        }

        $parts = $writer->finish();

        // 12 records with max 5 per part -> 3 parts (5, 5, 2)
        self::assertCount(3, $parts);
        self::assertSame('seo/keywords/part-000001.ndjson', $parts[0]->file);
        self::assertSame(5, $parts[0]->count);
        self::assertSame(5, $parts[1]->count);
        self::assertSame(2, $parts[2]->count);

        // Verify streaming reader reads all 12 records without loading full array
        $readCount = 0;
        foreach ($parts as $part) {
            $partPath = $dir . DIRECTORY_SEPARATOR . basename($part->file);
            foreach (NdjsonPartReader::read($partPath) as $idx => $rec) {
                $readCount++;
                self::assertArrayHasKey('ref', $rec);
                self::assertArrayHasKey('phrase', $rec);
            }
        }

        self::assertSame(12, $readCount);
    }

    public function test_ndjson_writer_splits_parts_on_max_bytes(): void
    {
        $dir = $this->tempDir . '/parts_by_bytes';
        // Max 200 bytes per part
        $writer = new NdjsonPartWriter($dir, 'content/articles', maxRecords: 1000, maxBytes: 200);

        for ($i = 1; $i <= 6; $i++) {
            $writer->writeRecord([
                'ref' => "article:{$i}",
                'title' => "Article Title Number {$i} with sufficiently long descriptive text to exceed bytes",
            ]);
        }

        $parts = $writer->finish();

        self::assertGreaterThan(1, count($parts));
        foreach ($parts as $part) {
            self::assertNotEmpty($part->sha256);
            self::assertGreaterThan(0, $part->bytes);
        }
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

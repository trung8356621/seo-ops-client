<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Support;

use App\Services\ClientTransfer\Exceptions\BlobChecksumMismatchException;

final class BlobManager
{
    public function __construct(
        public readonly string $blobDirectory,
        public readonly string $relativeSubdir = 'content/blobs',
    ) {
        if (! is_dir($blobDirectory)) {
            mkdir($blobDirectory, 0755, true);
        }
    }

    /**
     * Stores a raw blob and returns [relative_path, sha256].
     *
     * @return array{path: string, sha256: string}
     */
    public function store(string $content, string $extension = 'html'): array
    {
        $sha256 = hash('sha256', $content);
        $fileName = "{$sha256}.{$extension}";
        $fullPath = $this->blobDirectory . DIRECTORY_SEPARATOR . $fileName;

        if (! file_exists($fullPath)) {
            file_put_contents($fullPath, $content);
        }

        $relPath = str_replace('\\', '/', $this->relativeSubdir . '/' . $fileName);

        return [
            'path' => $relPath,
            'sha256' => $sha256,
        ];
    }

    /**
     * Reads a blob and verifies its checksum.
     */
    public function readAndVerify(
        string $fullPath,
        string $expectedSha256,
        string $dataset = 'unknown',
        string $recordRef = 'unknown',
        string $field = 'body',
    ): string {
        if (! file_exists($fullPath)) {
            throw new \RuntimeException("Blob file not found at [{$fullPath}].");
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw new \RuntimeException("Unable to read blob file [{$fullPath}].");
        }

        $actualSha256 = hash('sha256', $content);
        if ($actualSha256 !== $expectedSha256) {
            throw new BlobChecksumMismatchException(
                dataset: $dataset,
                recordRef: $recordRef,
                field: $field,
                expectedSha256: $expectedSha256,
                actualSha256: $actualSha256,
            );
        }

        return $content;
    }
}

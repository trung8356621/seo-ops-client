<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Support;

use App\Services\ClientTransfer\Manifest\PartManifest;

final class NdjsonPartWriter
{
    private int $currentPartIndex = 1;

    private int $currentPartCount = 0;

    private int $currentPartBytes = 0;

    /** @var resource|null */
    private $currentHandle = null;

    private string $currentFilePath = '';

    /** @var list<PartManifest> */
    private array $parts = [];

    public function __construct(
        public readonly string $directory,
        public readonly string $relativeSubdir,
        public readonly int $maxRecords = 20000,
        public readonly int $maxBytes = 16777216, // 16 MB
        int $startPartIndex = 1,
    ) {
        $this->currentPartIndex = $startPartIndex;
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function writeRecord(array $record): void
    {
        if ($this->currentHandle === null) {
            $this->openPart();
        }

        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $line = $json . "\n";
        $bytes = strlen($line);

        fwrite($this->currentHandle, $line);
        $this->currentPartCount++;
        $this->currentPartBytes += $bytes;

        if ($this->currentPartCount >= $this->maxRecords || $this->currentPartBytes >= $this->maxBytes) {
            $this->closePart();
        }
    }

    private function openPart(): void
    {
        $fileName = sprintf('part-%06d.ndjson', $this->currentPartIndex);
        $this->currentFilePath = $this->directory . DIRECTORY_SEPARATOR . $fileName;
        $handle = fopen($this->currentFilePath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open part file [{$this->currentFilePath}] for writing.");
        }
        $this->currentHandle = $handle;
        $this->currentPartCount = 0;
        $this->currentPartBytes = 0;
    }

    private function closePart(): void
    {
        if ($this->currentHandle !== null) {
            fclose($this->currentHandle);
            $this->currentHandle = null;

            if ($this->currentPartCount > 0 && file_exists($this->currentFilePath)) {
                $sha256 = hash_file('sha256', $this->currentFilePath);
                $relFile = str_replace('\\', '/', $this->relativeSubdir . '/' . basename($this->currentFilePath));
                $this->parts[] = new PartManifest(
                    file: $relFile,
                    count: $this->currentPartCount,
                    sha256: (string) $sha256,
                    bytes: $this->currentPartBytes,
                );
            }

            $this->currentPartIndex++;
        }
    }

    /**
     * @return list<PartManifest>
     */
    public function finish(): array
    {
        if ($this->currentHandle !== null) {
            $this->closePart();
        }

        return $this->parts;
    }
}

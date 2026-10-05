<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Support;

use Generator;

final class NdjsonPartReader
{
    /**
     * Streams records from an NDJSON file.
     *
     * @return Generator<int, array<string, mixed>> Yields [lineIndex => recordArray]
     */
    public static function read(string $filePath): Generator
    {
        if (! file_exists($filePath)) {
            throw new \RuntimeException("Part file not found [{$filePath}].");
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open part file [{$filePath}].");
        }

        try {
            $lineIndex = 0;
            while (($line = fgets($handle)) !== false) {
                $lineIndex++;
                $trimmed = trim($line, "\r\n");
                if ($trimmed === '') {
                    continue;
                }

                $record = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($record)) {
                    yield $lineIndex => $record;
                }
            }
        } finally {
            fclose($handle);
        }
    }
}

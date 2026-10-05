<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Manifest;

final class PartManifest
{
    public function __construct(
        public readonly string $file,
        public readonly int $count,
        public readonly string $sha256,
        public readonly int $bytes = 0,
    ) {
    }

    /**
     * @return array{file: string, count: int, sha256: string, bytes: int}
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'count' => $this->count,
            'sha256' => $this->sha256,
            'bytes' => $this->bytes,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            file: (string) ($data['file'] ?? ''),
            count: (int) ($data['count'] ?? 0),
            sha256: (string) ($data['sha256'] ?? ''),
            bytes: (int) ($data['bytes'] ?? 0),
        );
    }
}

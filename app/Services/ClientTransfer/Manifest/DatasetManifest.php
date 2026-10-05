<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Manifest;

final class DatasetManifest
{
    /**
     * @param  list<string>  $dependsOn
     * @param  list<PartManifest>  $parts
     */
    public function __construct(
        public readonly string $key,
        public readonly int $count,
        public readonly array $dependsOn,
        public readonly array $parts,
    ) {
    }

    /**
     * @return array{count: int, depends_on: list<string>, parts: list<array{file: string, count: int, sha256: string, bytes: int}>}
     */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'depends_on' => $this->dependsOn,
            'parts' => array_map(static fn (PartManifest $p): array => $p->toArray(), $this->parts),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $key, array $data): self
    {
        $parts = [];
        foreach ((array) ($data['parts'] ?? []) as $partData) {
            if (is_array($partData)) {
                $parts[] = PartManifest::fromArray($partData);
            }
        }

        return new self(
            key: $key,
            count: (int) ($data['count'] ?? 0),
            dependsOn: array_values(array_map('strval', (array) ($data['depends_on'] ?? []))),
            parts: $parts,
        );
    }
}

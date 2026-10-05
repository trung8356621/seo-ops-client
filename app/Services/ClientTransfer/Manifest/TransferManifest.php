<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Manifest;

use App\Services\ClientTransfer\Exceptions\UnsupportedFormatVersionException;

final class TransferManifest
{
    public const FORMAT = 'seo-ops-transfer';

    public const CURRENT_VERSION = 1;

    public const SUPPORTED_VERSIONS = [1];

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, DatasetManifest>  $datasets
     */
    public function __construct(
        public readonly string $format,
        public readonly int $formatVersion,
        public readonly string $exportedAt,
        public readonly array $source,
        public readonly array $datasets,
    ) {}

    public function validate(): void
    {
        if ($this->format !== self::FORMAT) {
            throw new UnsupportedFormatVersionException("Invalid package format [{$this->format}], expected [".self::FORMAT.'].');
        }

        if (! in_array($this->formatVersion, self::SUPPORTED_VERSIONS, true)) {
            throw new UnsupportedFormatVersionException("Unsupported transfer format version [{$this->formatVersion}]. Supported versions: ".implode(', ', self::SUPPORTED_VERSIONS));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $datasetArray = [];
        foreach ($this->datasets as $key => $manifest) {
            $datasetArray[$key] = $manifest->toArray();
        }

        return [
            'format' => $this->format,
            'format_version' => $this->formatVersion,
            'exported_at' => $this->exportedAt,
            'source' => $this->source,
            'datasets' => $datasetArray,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $datasets = [];
        foreach ((array) ($data['datasets'] ?? []) as $key => $dData) {
            if (is_array($dData)) {
                $datasets[$key] = DatasetManifest::fromArray((string) $key, $dData);
            }
        }

        $manifest = new self(
            format: (string) ($data['format'] ?? ''),
            formatVersion: (int) ($data['format_version'] ?? 0),
            exportedAt: (string) ($data['exported_at'] ?? ''),
            source: (array) ($data['source'] ?? []),
            datasets: $datasets,
        );

        $manifest->validate();

        return $manifest;
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new UnsupportedFormatVersionException('Invalid manifest JSON.');
        }

        return self::fromArray($decoded);
    }
}

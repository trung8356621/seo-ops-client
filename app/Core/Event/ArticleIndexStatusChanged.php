<?php

declare(strict_types=1);

namespace App\Core\Event;

use App\Core\Event\Contracts\DomainEvent;
use Illuminate\Support\Carbon;

/**
 * Cross-addon signal: SEO/Content index marker changed.
 * Seeding (and others) may listen — emitters must not import Seeding.
 */
final class ArticleIndexStatusChanged implements DomainEvent
{
    public const NAME = 'content.article_index_status_changed';

    public function __construct(
        public readonly int $articleId,
        public readonly int $siteId,
        public readonly bool $indexed,
        public readonly ?string $indexedAtIso,
        public readonly ?string $previousIndexedAtIso = null,
        public readonly ?string $articleTitle = null,
        public readonly ?string $articleUrl = null,
        public readonly ?string $thumbnailUrl = null,
        public readonly ?string $domain = null,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'article_id' => $this->articleId,
            'site_id' => $this->siteId,
            'indexed' => $this->indexed,
            'indexed_at' => $this->indexedAtIso,
            'previous_indexed_at' => $this->previousIndexedAtIso,
            'article_title' => $this->articleTitle,
            'article_url' => $this->articleUrl,
            'thumbnail_url' => $this->thumbnailUrl,
            'domain' => $this->domain,
        ];
    }

    public function indexedAt(): ?Carbon
    {
        if ($this->indexedAtIso === null || $this->indexedAtIso === '') {
            return null;
        }

        return Carbon::parse($this->indexedAtIso);
    }
}

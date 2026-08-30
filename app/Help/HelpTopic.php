<?php

declare(strict_types=1);

namespace App\Help;

/**
 * Normalized Help topic from Markdown frontmatter + body.
 * Existence on Git/cache = topic exists (no draft/published status).
 */
final class HelpTopic
{
    /**
     * @param  list<string>  $keywords
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $group,
        public readonly int $sortOrder,
        public readonly array $keywords,
        public readonly ?string $updatedAt,
        public readonly string $bodyMarkdown,
        public readonly string $path,
        public readonly string $html = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toClientTopic(): array
    {
        return [
            'id' => $this->key,
            'key' => $this->key,
            'title' => $this->title,
            'summary' => $this->summary,
            'content' => $this->bodyMarkdown,
            'html' => $this->html,
            'keywords' => $this->keywords,
            'sort_order' => $this->sortOrder,
            'updated_at' => $this->updatedAt,
            'path' => $this->path,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'summary' => $this->summary,
            'group' => $this->group,
            'sort_order' => $this->sortOrder,
            'keywords' => $this->keywords,
            'updated_at' => $this->updatedAt,
            'body_markdown' => $this->bodyMarkdown,
            'path' => $this->path,
            'html' => $this->html,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $keywords = $data['keywords'] ?? [];

        return new self(
            key: (string) ($data['key'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            summary: (string) ($data['summary'] ?? ''),
            group: (string) ($data['group'] ?? 'getting-started'),
            sortOrder: (int) ($data['sort_order'] ?? 0),
            keywords: array_values(array_map('strval', is_array($keywords) ? $keywords : [])),
            updatedAt: HelpContextKeyBuilder::normalizeUpdatedAt($data['updated_at'] ?? null),
            bodyMarkdown: (string) ($data['body_markdown'] ?? ''),
            path: (string) ($data['path'] ?? ''),
            html: (string) ($data['html'] ?? ''),
        );
    }
}

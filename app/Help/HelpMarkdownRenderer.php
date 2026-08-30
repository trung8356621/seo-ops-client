<?php

declare(strict_types=1);

namespace App\Help;

use Omnichannel\Addons\Content\Support\SimpleMarkdownHtmlConverter;

/**
 * Single Markdown → HTML path for Help (reuses Article CommonMark converter).
 */
final class HelpMarkdownRenderer
{
    public function __construct(
        private readonly SimpleMarkdownHtmlConverter $converter = new SimpleMarkdownHtmlConverter(),
    ) {}

    public function toHtml(string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        return $this->converter->toHtml($markdown);
    }

    public function withHtml(HelpTopic $topic): HelpTopic
    {
        return new HelpTopic(
            key: $topic->key,
            title: $topic->title,
            summary: $topic->summary,
            group: $topic->group,
            sortOrder: $topic->sortOrder,
            keywords: $topic->keywords,
            updatedAt: $topic->updatedAt,
            bodyMarkdown: $topic->bodyMarkdown,
            path: $topic->path,
            html: $this->toHtml($topic->bodyMarkdown),
        );
    }
}

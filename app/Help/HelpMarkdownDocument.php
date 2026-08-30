<?php

declare(strict_types=1);

namespace App\Help;

use Symfony\Component\Yaml\Yaml;

/**
 * Parse / serialize Help Markdown files (YAML frontmatter + body).
 * No status / video / related in MVP contract.
 */
final class HelpMarkdownDocument
{
    /**
     * @return array{meta: array<string, mixed>, body: string}
     */
    public function parse(string $markdown): array
    {
        $markdown = str_replace("\r\n", "\n", $markdown);
        if (! preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $markdown, $matches)) {
            return [
                'meta' => [],
                'body' => trim($markdown),
            ];
        }

        $rawMeta = Yaml::parse($matches[1]);
        $meta = is_array($rawMeta) ? $rawMeta : [];

        return [
            'meta' => $meta,
            'body' => trim((string) $matches[2]),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function serialize(array $meta, string $body): string
    {
        $updatedAt = HelpContextKeyBuilder::normalizeUpdatedAt($meta['updated_at'] ?? null)
            ?? date('Y-m-d H:i');

        $ordered = [
            'key' => (string) ($meta['key'] ?? ''),
            'title' => (string) ($meta['title'] ?? ''),
            'summary' => (string) ($meta['summary'] ?? ''),
            'group' => (string) ($meta['group'] ?? ''),
            'sort_order' => (int) ($meta['sort_order'] ?? 0),
            'keywords' => array_values(array_map('strval', is_array($meta['keywords'] ?? null) ? $meta['keywords'] : [])),
            // Quoted string so Symfony Yaml does not coerce to unix timestamp
            'updated_at' => (string) $updatedAt,
        ];

        $yaml = Yaml::dump($ordered, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        // Force updated_at as quoted scalar if dump emitted unquoted date-like value
        $yaml = preg_replace(
            '/^updated_at:\s*(.+)$/m',
            'updated_at: \''.addslashes((string) $updatedAt).'\'',
            $yaml,
        ) ?? $yaml;

        $body = trim(str_replace("\r\n", "\n", $body));

        return "---\n".$yaml."---\n\n".$body."\n";
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function toTopic(string $markdown, string $path): HelpTopic
    {
        $parsed = $this->parse($markdown);
        $meta = $parsed['meta'];
        $body = $parsed['body'];

        $key = trim((string) ($meta['key'] ?? ''));
        $title = trim((string) ($meta['title'] ?? ''));
        $summary = trim((string) ($meta['summary'] ?? ''));
        $group = trim((string) ($meta['group'] ?? ''));

        if ($key === '') {
            throw new \InvalidArgumentException('Help topic missing key: '.$path);
        }
        if ($title === '') {
            throw new \InvalidArgumentException('Help topic missing title: '.$key);
        }
        if ($summary === '') {
            throw new \InvalidArgumentException('Help topic missing summary: '.$key);
        }
        if ($group === '') {
            throw new \InvalidArgumentException('Help topic missing group: '.$key);
        }
        if (trim($body) === '') {
            throw new \InvalidArgumentException('Help topic missing content: '.$key);
        }

        $keywords = $meta['keywords'] ?? [];

        return new HelpTopic(
            key: $key,
            title: $title,
            summary: $summary,
            group: $group,
            sortOrder: (int) ($meta['sort_order'] ?? 0),
            keywords: array_values(array_map('strval', is_array($keywords) ? $keywords : [])),
            updatedAt: HelpContextKeyBuilder::normalizeUpdatedAt($meta['updated_at'] ?? null),
            bodyMarkdown: $body,
            path: $path,
        );
    }
}

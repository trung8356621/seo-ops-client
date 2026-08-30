<?php

declare(strict_types=1);

namespace App\Help;

/**
 * Minimal HTML → Markdown for Help TipTap subset (no extra Composer package).
 */
final class HelpHtmlToMarkdownConverter
{
    public function convert(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<div id="help-html-root">'.$html.'</div>';
        $doc->loadHTML('<?xml encoding="UTF-8">'.$wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('help-html-root');
        if ($root === null) {
            return trim(strip_tags($html));
        }

        $parts = [];
        foreach ($root->childNodes as $child) {
            $chunk = $this->renderNode($child, false);
            if ($chunk !== '') {
                $parts[] = $chunk;
            }
        }

        $markdown = implode("\n\n", $parts);
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown;

        return trim($markdown);
    }

    private function renderNode(\DOMNode $node, bool $inline): string
    {
        if ($node instanceof \DOMText) {
            $text = $node->wholeText;
            if ($inline) {
                return $this->escapeInline($text);
            }

            return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        }

        if (! $node instanceof \DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);

        return match ($tag) {
            'h1' => '# '.$this->renderInlineChildren($node),
            'h2' => '## '.$this->renderInlineChildren($node),
            'h3' => '### '.$this->renderInlineChildren($node),
            'h4' => '#### '.$this->renderInlineChildren($node),
            'p' => $this->renderInlineChildren($node),
            'strong', 'b' => '**'.$this->renderInlineChildren($node).'**',
            'em', 'i' => '*'.$this->renderInlineChildren($node).'*',
            'code' => '`'.$this->renderInlineChildren($node).'`',
            'pre' => "```\n".$this->plainText($node)."\n```",
            'blockquote' => $this->renderBlockquote($node),
            'ul' => $this->renderList($node, false),
            'ol' => $this->renderList($node, true),
            'li' => $this->renderInlineChildren($node),
            'a' => $this->renderLink($node),
            'img' => $this->renderImage($node),
            'hr' => '---',
            'br' => "  \n",
            'table' => $this->renderTable($node),
            'div', 'span' => $inline
                ? $this->renderInlineChildren($node)
                : $this->renderBlockChildren($node),
            default => $inline
                ? $this->renderInlineChildren($node)
                : $this->renderBlockChildren($node),
        };
    }

    private function renderInlineChildren(\DOMElement $el): string
    {
        $out = '';
        foreach ($el->childNodes as $child) {
            $out .= $this->renderNode($child, true);
        }

        return trim(preg_replace('/\s+/u', ' ', $out) ?? $out);
    }

    private function renderBlockChildren(\DOMElement $el): string
    {
        $parts = [];
        foreach ($el->childNodes as $child) {
            $chunk = $this->renderNode($child, false);
            if ($chunk !== '') {
                $parts[] = $chunk;
            }
        }

        return implode("\n\n", $parts);
    }

    private function renderBlockquote(\DOMElement $el): string
    {
        $inner = $this->renderBlockChildren($el);
        $lines = preg_split('/\r\n|\r|\n/', $inner) ?: [];
        $quoted = array_map(
            static fn (string $line): string => '> '.ltrim($line),
            $lines,
        );

        return implode("\n", $quoted);
    }

    private function renderList(\DOMElement $el, bool $ordered): string
    {
        $lines = [];
        $index = 1;
        foreach ($el->childNodes as $child) {
            if (! $child instanceof \DOMElement || strtolower($child->tagName) !== 'li') {
                continue;
            }
            $prefix = $ordered ? $index.'. ' : '- ';
            $lines[] = $prefix.$this->renderInlineChildren($child);
            $index++;
        }

        return implode("\n", $lines);
    }

    private function renderLink(\DOMElement $el): string
    {
        $text = $this->renderInlineChildren($el);
        $href = trim((string) $el->getAttribute('href'));
        if ($href === '') {
            return $text;
        }

        return '['.$text.']('.$href.')';
    }

    private function renderImage(\DOMElement $el): string
    {
        $alt = trim((string) $el->getAttribute('alt'));
        $src = trim((string) $el->getAttribute('src'));
        if ($src === '') {
            return '';
        }

        return '!['.$alt.']('.$src.')';
    }

    private function renderTable(\DOMElement $el): string
    {
        $rows = [];
        foreach ($el->getElementsByTagName('tr') as $tr) {
            if (! $tr instanceof \DOMElement) {
                continue;
            }
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if (! $cell instanceof \DOMElement) {
                    continue;
                }
                $cellTag = strtolower($cell->tagName);
                if (! in_array($cellTag, ['td', 'th'], true)) {
                    continue;
                }
                $cells[] = str_replace('|', '\\|', $this->renderInlineChildren($cell));
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return '';
        }

        $colCount = max(array_map('count', $rows));
        $normalize = static function (array $cells) use ($colCount): array {
            while (count($cells) < $colCount) {
                $cells[] = '';
            }

            return $cells;
        };

        $header = $normalize($rows[0]);
        $lines = [
            '| '.implode(' | ', $header).' |',
            '| '.implode(' | ', array_fill(0, $colCount, '---')).' |',
        ];
        for ($i = 1, $n = count($rows); $i < $n; $i++) {
            $lines[] = '| '.implode(' | ', $normalize($rows[$i])).' |';
        }

        return implode("\n", $lines);
    }

    private function plainText(\DOMNode $node): string
    {
        return trim($node->textContent ?? '');
    }

    private function escapeInline(string $text): string
    {
        return str_replace(['*', '_', '`', '['], ['\\*', '\\_', '\\`', '\\['], $text);
    }
}

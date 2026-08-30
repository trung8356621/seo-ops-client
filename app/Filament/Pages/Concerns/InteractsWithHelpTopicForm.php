<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Help\HelpCacheStore;
use App\Help\HelpContextKeyBuilder;
use App\Help\HelpContextKeyRegistry;
use App\Help\HelpGroupRegistry;
use App\Help\HelpMarkdownRenderer;
use App\Help\HelpPublishService;
use App\Help\HelpTopic;
use Filament\Notifications\Notification;

trait InteractsWithHelpTopicForm
{
    public string $formKey = '';

    public string $formTitle = '';

    public string $formSummary = '';

    public string $formGroup = 'websites-domains';

    public int $formSortOrder = 0;

    public string $formKeywords = '';

    public string $formPath = '';

    public string $formHtml = '<p></p>';

    public string $previewHtml = '';

    public bool $previewOpen = false;

    public bool $autoKeyFromTitle = true;

    /**
     * @return array<string, string>
     */
    public function groupOptions(): array
    {
        return HelpGroupRegistry::options();
    }

    public function updatedFormTitle(string $value): void
    {
        if (! $this->autoKeyFromTitle) {
            return;
        }
        if (trim($this->formGroup) === '') {
            return;
        }
        $this->formKey = HelpContextKeyBuilder::fromGroupAndTitle($this->formGroup, $value);
    }

    public function updatedFormGroup(string $value): void
    {
        if (! $this->autoKeyFromTitle) {
            return;
        }
        if (trim($this->formTitle) === '') {
            $prefix = HelpGroupRegistry::contextPrefix($value);
            $this->formKey = $prefix.'.';

            return;
        }
        $this->formKey = HelpContextKeyBuilder::fromGroupAndTitle($value, $this->formTitle);
    }

    public function updateEditorHtml(string $html): void
    {
        $this->formHtml = $html;
    }

    public function openPreview(): void
    {
        $this->previewHtml = app(HelpPublishService::class)->previewHtmlFromEditorHtml($this->formHtml);
        $this->previewOpen = true;
    }

    public function closePreview(): void
    {
        $this->previewOpen = false;
    }

    public function publishTopic(): void
    {
        $keywords = array_values(array_filter(array_map(
            static fn (string $s): string => trim($s),
            preg_split('/[,]+/', $this->formKeywords) ?: [],
        )));

        $result = app(HelpPublishService::class)->publish([
            'key' => $this->formKey,
            'title' => $this->formTitle,
            'summary' => $this->formSummary,
            'group' => $this->formGroup,
            'sort_order' => $this->formSortOrder,
            'keywords' => $keywords,
            'html' => $this->formHtml,
            'path' => $this->formPath !== '' ? $this->formPath : null,
        ]);

        if (($result['ok'] ?? false) !== true) {
            Notification::make()
                ->title('Publish failed')
                ->body((string) ($result['error'] ?? 'unknown'))
                ->danger()
                ->send();

            return;
        }

        $this->formPath = (string) ($result['path'] ?? $this->formPath);
        $this->autoKeyFromTitle = false;

        Notification::make()
            ->title(($result['source'] ?? '') === 'local-repo' ? 'Saved to local Help repo' : 'Published to Git')
            ->body('Version '.($result['version'] ?? '—'))
            ->success()
            ->send();
    }

    protected function fillFromTopic(?HelpTopic $topic, ?array $registry = null): void
    {
        $this->formKey = $topic?->key ?? (string) ($registry['key'] ?? '');
        $this->formTitle = $topic?->title ?? (string) ($registry['label'] ?? '');
        $this->formSummary = $topic?->summary ?? '';
        $this->formGroup = $topic?->group ?? (string) ($registry['group'] ?? 'websites-domains');
        $this->formSortOrder = $topic?->sortOrder ?? 0;
        $this->formKeywords = $topic ? implode(', ', $topic->keywords) : '';
        $this->formPath = $topic?->path ?? '';
        $this->formHtml = $topic !== null
            ? ($topic->html !== '' ? $topic->html : app(HelpMarkdownRenderer::class)->toHtml($topic->bodyMarkdown))
            : '<p></p>';
        if (trim($this->formHtml) === '') {
            $this->formHtml = '<p></p>';
        }
        $this->previewHtml = '';
        $this->previewOpen = false;
        $this->autoKeyFromTitle = $topic === null;
    }

    protected function findCachedTopic(string $key): ?HelpTopic
    {
        foreach (app(HelpCacheStore::class)->readTopics() as $topic) {
            if ($topic->key === $key) {
                return $topic;
            }
        }

        return null;
    }

    /**
     * @return array{key: string, group: string, label: string}|null
     */
    protected function findRegistry(string $key): ?array
    {
        foreach (HelpContextKeyRegistry::all() as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        return null;
    }
}

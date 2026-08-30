<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\InteractsWithHelpTopicForm;
use App\Help\HelpServiceProvider;
use App\Models\User;
use Filament\Pages\Page;

/**
 * Edit one Help Topic (1 Markdown file). Editor is the main focus.
 */
final class HelpTopicEdit extends Page
{
    use InteractsWithHelpTopicForm;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $slug = 'help-topics/{topic}/edit';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.help-topic-edit';

    public string $topic = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && HelpServiceProvider::userCanManageHelp($user);
    }

    public function getTitle(): string
    {
        return $this->formTitle !== '' ? $this->formTitle : 'Edit Help Topic';
    }

    public function mount(string $topic): void
    {
        abort_unless(static::canAccess(), 403);

        $this->topic = rawurldecode($topic);
        $cached = $this->findCachedTopic($this->topic);
        $registry = $this->findRegistry($this->topic);

        if ($cached === null && $registry === null) {
            abort(404, 'Help topic not found: '.$this->topic);
        }

        $this->fillFromTopic($cached, $registry);
        $this->formKey = $this->topic;
        $this->autoKeyFromTitle = false;
    }

    public function listUrl(): string
    {
        return HelpTopicsAdmin::getUrl();
    }

    public function publish(): void
    {
        $this->publishTopic();
    }
}

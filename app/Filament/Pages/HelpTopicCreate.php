<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\InteractsWithHelpTopicForm;
use App\Help\HelpGroupRegistry;
use App\Help\HelpServiceProvider;
use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Request;

/**
 * Create Help Topic — prefill from Missing key and/or group.
 */
final class HelpTopicCreate extends Page
{
    use InteractsWithHelpTopicForm;

    protected static ?string $navigationIcon = 'heroicon-o-plus';

    protected static ?string $slug = 'help-topics/create';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.help-topic-edit';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && HelpServiceProvider::userCanManageHelp($user);
    }

    public function getTitle(): string
    {
        return 'New Help Topic';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $key = trim((string) Request::query('key', ''));
        $group = trim((string) Request::query('group', ''));
        $registry = $key !== '' ? $this->findRegistry($key) : null;
        $existing = $key !== '' ? $this->findCachedTopic($key) : null;

        if ($existing !== null) {
            $this->redirect(HelpTopicEdit::getUrl(['topic' => $existing->key]));

            return;
        }

        $this->fillFromTopic(null, $registry);

        if ($group !== '' && HelpGroupRegistry::find($group) !== null) {
            $this->formGroup = $group;
            $this->formSortOrder = HelpGroupRegistry::nextTopicSortOrder($group);
            $prefix = HelpGroupRegistry::contextPrefix($group);
            if ($key === '') {
                $this->formKey = $prefix.'.';
                $this->autoKeyFromTitle = true;
            }
        }

        if ($key !== '') {
            $this->formKey = $key;
            $this->autoKeyFromTitle = false;
        }
        if ($registry !== null) {
            $this->formTitle = $registry['label'];
            $this->formGroup = $registry['group'];
            if ($this->formSortOrder === 0) {
                $this->formSortOrder = HelpGroupRegistry::nextTopicSortOrder($this->formGroup);
            }
        }
    }

    public function listUrl(): string
    {
        return HelpTopicsAdmin::getUrl();
    }

    public function publish(): void
    {
        $this->publishTopic();
        if ($this->formKey !== '') {
            $this->redirect(HelpTopicEdit::getUrl(['topic' => $this->formKey]));
        }
    }
}

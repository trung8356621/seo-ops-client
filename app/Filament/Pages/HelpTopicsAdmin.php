<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Help\HelpCacheStore;
use App\Help\HelpContextKeyBuilder;
use App\Help\HelpContextKeyRegistry;
use App\Help\HelpCoverageService;
use App\Help\HelpGroupRegistry;
use App\Help\HelpPublishService;
use App\Help\HelpRemoteSyncService;
use App\Help\HelpServiceProvider;
use App\Help\HelpTopic;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;

/**
 * Help Topic List — all canonical groups (incl. empty), coverage Covered/Missing/Unused.
 */
final class HelpTopicsAdmin extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationGroup = 'Hệ thống';

    protected static ?string $slug = 'help-topics';

    protected static ?int $navigationSort = 40;

    protected static string $view = 'filament.pages.help-topics-admin';

    public string $search = '';

    public string $filterGroup = '';

    public string $filterCoverage = '';

    public static function getNavigationLabel(): string
    {
        return 'Help Topics';
    }

    public function getTitle(): string
    {
        return 'Help Topics';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && HelpServiceProvider::userCanManageHelp($user);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        app(HelpRemoteSyncService::class)->sync(force: false);
    }

    /**
     * @return array{covered: int, missing: int, unused: int, version: string|null}
     */
    #[Computed]
    public function coverageSummary(): array
    {
        $c = app(HelpCoverageService::class)->analyze();

        return [
            'covered' => count($c['covered']),
            'missing' => count($c['missing']),
            'unused' => count($c['unused']),
            'version' => app(HelpCacheStore::class)->cachedVersion(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function groupOptions(): array
    {
        return HelpGroupRegistry::options();
    }

    /**
     * Always render every canonical group (0 topics included).
     *
     * @return list<array{group_id: string, group_title: string, sort_order: int, rows: list<array<string, mixed>>}>
     */
    #[Computed]
    public function groupedRows(): array
    {
        $coverage = app(HelpCoverageService::class)->analyze();
        $coverageByKey = [];
        foreach ($coverage['rows'] as $row) {
            $coverageByKey[$row['key']] = $row;
        }

        $topics = app(HelpCacheStore::class)->readTopics();
        $byKey = [];
        foreach ($topics as $topic) {
            $byKey[$topic->key] = $topic;
        }

        $rows = [];
        foreach (HelpContextKeyRegistry::all() as $reg) {
            $key = $reg['key'];
            $topic = $byKey[$key] ?? null;
            $cov = $coverageByKey[$key]['coverage'] ?? 'missing';
            $rows[] = $this->normalizeRow($key, $topic, $cov, $reg['label'], $reg['group']);
        }

        foreach ($topics as $topic) {
            if (HelpContextKeyRegistry::has($topic->key)) {
                continue;
            }
            $cov = $coverageByKey[$topic->key]['coverage'] ?? 'unused';
            $rows[] = $this->normalizeRow($topic->key, $topic, $cov, $topic->title, $topic->group);
        }

        $search = mb_strtolower(trim($this->search));
        $rows = array_values(array_filter($rows, function (array $row) use ($search): bool {
            if ($this->filterGroup !== '' && ($row['group'] ?? '') !== $this->filterGroup) {
                return false;
            }
            if ($this->filterCoverage !== '' && ($row['coverage'] ?? '') !== $this->filterCoverage) {
                return false;
            }
            if ($search === '') {
                return true;
            }
            $hay = mb_strtolower(implode(' ', [
                (string) ($row['key'] ?? ''),
                (string) ($row['title'] ?? ''),
                (string) ($row['summary'] ?? ''),
                (string) ($row['label'] ?? ''),
            ]));

            return str_contains($hay, $search);
        }));

        $buckets = [];
        foreach (HelpGroupRegistry::all() as $group) {
            $buckets[$group['id']] = [
                'group_id' => $group['id'],
                'group_title' => $group['title'],
                'sort_order' => (int) $group['sort_order'],
                'rows' => [],
            ];
        }

        $extra = [];
        foreach ($rows as $row) {
            $gid = (string) ($row['group'] ?? '') ?: '_ungrouped';
            if (isset($buckets[$gid])) {
                $buckets[$gid]['rows'][] = $row;
                continue;
            }
            if (! isset($extra[$gid])) {
                $extra[$gid] = [
                    'group_id' => $gid,
                    'group_title' => $gid === '_ungrouped' ? 'Ungrouped' : $gid,
                    'sort_order' => 9999,
                    'rows' => [],
                ];
            }
            $extra[$gid]['rows'][] = $row;
        }

        foreach ($buckets as &$bucket) {
            usort(
                $bucket['rows'],
                static function (array $a, array $b): int {
                    $sa = (int) ($a['sort_order'] ?? 0);
                    $sb = (int) ($b['sort_order'] ?? 0);

                    return $sa <=> $sb ?: strcmp((string) $a['title'], (string) $b['title']);
                },
            );
        }
        unset($bucket);

        return array_values(array_merge(array_values($buckets), array_values($extra)));
    }

    public function updateGroupSortOrder(string $groupId, mixed $order): void
    {
        abort_unless(static::canAccess(), 403);

        $result = app(HelpPublishService::class)->updateGroupSortOrder(
            $groupId,
            (int) $order,
        );

        unset($this->groupedRows);

        if (($result['ok'] ?? false) !== true) {
            Notification::make()
                ->title('Không cập nhật được group order')
                ->body((string) ($result['error'] ?? 'unknown'))
                ->danger()
                ->send();

            return;
        }

        if (($result['published'] ?? false) === true) {
            Notification::make()
                ->title('Đã lưu group order')
                ->body('Đã publish lên Help repo (Global Help dùng chung).')
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('Đã lưu group order (local)')
            ->body(($result['error'] ?? null)
                ? 'Local OK · remote: '.$result['error']
                : 'Local cache updated. GitHub write chưa cấu hình — Sync/publish token để chia sẻ remote.')
            ->warning()
            ->send();
    }

    public function syncRemote(): void
    {
        $result = app(HelpRemoteSyncService::class)->sync(force: true);
        if (($result['ok'] ?? false) === true) {
            Notification::make()
                ->title('Help cache synced')
                ->body('Version: '.($result['version'] ?? '—').' · Topics: '.($result['topic_count'] ?? 0))
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Help sync failed — using last-known-good')
                ->body((string) ($result['error'] ?? 'unknown'))
                ->warning()
                ->send();
        }
    }

    public function editUrl(string $key): string
    {
        return HelpTopicEdit::getUrl(['topic' => $key]);
    }

    public function createUrl(?string $key = null, ?string $group = null): string
    {
        $params = [];
        if ($key !== null && $key !== '') {
            $params['key'] = $key;
        }
        if ($group !== null && $group !== '') {
            $params['group'] = $group;
        }

        return HelpTopicCreate::getUrl($params);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeRow(
        string $key,
        ?HelpTopic $topic,
        string $coverage,
        string $label,
        string $fallbackGroup = '',
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'title' => $topic?->title ?? $label,
            'summary' => $topic?->summary ?? '',
            'group' => $topic?->group ?: $fallbackGroup,
            'coverage' => $coverage,
            'path' => $topic?->path ?? '',
            'sort_order' => $topic?->sortOrder ?? 0,
            'updated_at' => $topic?->updatedAt ?? '',
            'updated_at_display' => HelpContextKeyBuilder::formatUpdatedAtForAdmin($topic?->updatedAt),
        ];
    }
}

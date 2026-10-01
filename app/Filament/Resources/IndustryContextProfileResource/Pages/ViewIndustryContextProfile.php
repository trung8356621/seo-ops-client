<?php

declare(strict_types=1);

namespace App\Filament\Resources\IndustryContextProfileResource\Pages;

use App\Filament\Resources\IndustryContextProfileResource;
use App\IndustryContext\IndustryContextProfileManager;
use App\Models\IndustryContextProfile;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewIndustryContextProfile extends ViewRecord
{
    protected static string $resource = IndustryContextProfileResource::class;

    protected static string $view = 'filament.resources.industry-context-profile-resource.pages.view-industry-context-profile';

    public function activateRevision(int $id): void
    {
        $profile = IndustryContextProfile::query()->where('key', $this->record->key)->findOrFail($id);
        $this->record = app(IndustryContextProfileManager::class)->activate($profile);
        Notification::make()->title('Đã dùng bản này')->success()->send();
    }

    public function revisions()
    {
        return app(IndustryContextProfileManager::class)->revisions((string) $this->record->key);
    }
}

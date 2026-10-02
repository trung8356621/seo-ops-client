<?php

declare(strict_types=1);

namespace App\Filament\Resources\IndustryContextProfileResource\Pages;

use App\Filament\Resources\IndustryContextProfileResource;
use App\IndustryContext\IndustryContextProfileManager;
use App\Models\IndustryContextProfile;
use Filament\Resources\Pages\ViewRecord;

final class ViewIndustryContextProfile extends ViewRecord
{
    protected static string $resource = IndustryContextProfileResource::class;

    public function mount(int|string $record): void
    {
        $profile = IndustryContextProfile::query()->findOrFail($record);
        $this->record = $profile;
        $core = app(IndustryContextProfileManager::class)->active($profile->key, IndustryContextProfile::TYPE_CORE)
            ?? IndustryContextProfile::query()->where('key', $profile->key)->where('type', IndustryContextProfile::TYPE_CORE)->latest('id')->firstOrFail();

        $this->redirect(IndustryContextProfileResource::getUrl('edit', ['record' => $core]));
    }
}

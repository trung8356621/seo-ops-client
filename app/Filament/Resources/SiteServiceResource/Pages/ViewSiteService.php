<?php

declare(strict_types=1);

namespace App\Filament\Resources\SiteServiceResource\Pages;

use App\Filament\Resources\SiteServiceResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSiteService extends ViewRecord
{
    protected static string $resource = SiteServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (): bool => SiteServiceResource::canEdit($this->getRecord())),
        ];
    }
}

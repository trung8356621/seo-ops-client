<?php

declare(strict_types=1);

namespace App\Filament\Resources\SeedingDatabaseConnectionResource\Pages;

use App\Filament\Pages\ServiceConfigure;
use App\Filament\Resources\SeedingDatabaseConnectionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListSeedingDatabaseConnections extends ListRecords
{
    protected static string $resource = SeedingDatabaseConnectionResource::class;

    public function mount(): void
    {
        $this->redirect(ServiceConfigure::getUrl(['service' => 'seeding']));
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn (): bool => SeedingDatabaseConnectionResource::canCreate()),
        ];
    }
}

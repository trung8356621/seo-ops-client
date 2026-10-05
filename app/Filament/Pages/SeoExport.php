<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\ClientTransfer\ClientTransferExporter;
use App\Services\ServiceIdentity;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class SeoExport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $slug = 'services/seo/export';

    protected static string $view = 'filament.pages.seo-export';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed>|null */
    public ?array $exportResult = null;

    public bool $isExporting = false;

    public function getTitle(): string
    {
        return 'SEO Portable Data Export';
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && in_array((string) $user->role, [User::ROLE_OWNER, User::ROLE_ADMIN], true);
    }

    public function runExport(): void
    {
        $this->isExporting = true;
        try {
            $exporter = new ClientTransferExporter();
            $this->exportResult = $exporter->export();

            Notification::make()
                ->title('SEO package exported successfully')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Export failed: ' . $e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->isExporting = false;
        }
    }

    public function downloadPackage(): ?BinaryFileResponse
    {
        if ($this->exportResult === null || empty($this->exportResult['destination_path'])) {
            return null;
        }

        $path = (string) $this->exportResult['destination_path'];
        if (! file_exists($path)) {
            Notification::make()->title('Export file no longer exists.')->danger()->send();
            return null;
        }

        return response()->download($path);
    }
}

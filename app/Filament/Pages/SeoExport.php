<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Jobs\ClientTransfer\PrepareSeoExportJob;
use App\Models\ClientTransferRun;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class SeoExport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $slug = 'services/seo/export';

    protected static string $view = 'filament.pages.seo-export';

    protected static bool $shouldRegisterNavigation = false;

    public ?string $runId = null;

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
        $this->runId = Str::random(12);

        ClientTransferRun::query()->create([
            'run_id' => $this->runId,
            'type' => 'export',
            'status' => 'pending',
            'phase' => 'queued',
            'started_at' => now(),
        ]);

        PrepareSeoExportJob::dispatch($this->runId)->onQueue('client-transfer');

        Notification::make()
            ->title('Đã đưa tác vụ xuất dữ liệu vào hàng đợi (client-transfer)')
            ->info()
            ->send();
    }

    public function getRunProperty(): ?ClientTransferRun
    {
        return $this->runId ? ClientTransferRun::query()->where('run_id', $this->runId)->first() : null;
    }

    public function downloadPackage(): ?BinaryFileResponse
    {
        $run = $this->run;
        if ($run === null || ! $run->isCompleted() || empty($run->artifact_path)) {
            Notification::make()->title('File export chưa sẵn sàng.')->warning()->send();
            return null;
        }

        $path = (string) $run->artifact_path;
        if (! file_exists($path)) {
            Notification::make()->title('File export không còn tồn tại trên disk.')->danger()->send();
            return null;
        }

        return response()->download($path);
    }
}

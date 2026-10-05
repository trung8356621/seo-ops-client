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
        if ($this->activeRun !== null) {
            Notification::make()->title('Một tác vụ xuất dữ liệu đang chạy.')->warning()->send();

            return;
        }

        $runId = Str::random(12);
        $this->runId = $runId;

        ClientTransferRun::query()->create([
            'run_id' => $runId,
            'type' => 'export',
            'status' => 'pending',
            'phase' => 'queued',
            'started_at' => now(),
        ]);

        PrepareSeoExportJob::dispatch($runId)->onQueue('client-transfer');

        Notification::make()
            ->title('Đã đưa tác vụ xuất dữ liệu vào hàng đợi (client-transfer)')
            ->info()
            ->send();
    }

    public function getActiveRunProperty(): ?ClientTransferRun
    {
        return ClientTransferRun::query()
            ->where('type', 'export')
            ->whereIn('status', ['pending', 'running'])
            ->orderByDesc('id')
            ->first();
    }

    public function getLatestRunProperty(): ?ClientTransferRun
    {
        return ClientTransferRun::query()
            ->where('type', 'export')
            ->where('status', 'completed')
            ->whereNotNull('artifact_path')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->get()
            ->first(static fn (ClientTransferRun $r): bool => is_file((string) $r->artifact_path));
    }

    /** Last run of this session if it failed (informational only). */
    public function getFailedRunProperty(): ?ClientTransferRun
    {
        if ($this->runId === null) {
            return null;
        }
        $run = ClientTransferRun::query()->where('run_id', $this->runId)->first();

        return $run?->isFailed() ? $run : null;
    }

    public function getLatestFileSizeProperty(): ?string
    {
        $run = $this->latestRun;
        if ($run === null) {
            return null;
        }
        $bytes = (int) @filesize((string) $run->artifact_path);
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2).' '.$units[$i];
    }

    public function downloadPackage(): ?BinaryFileResponse
    {
        $run = $this->latestRun;
        if ($run === null) {
            Notification::make()->title('Chưa có file export nào sẵn sàng.')->warning()->send();

            return null;
        }

        return response()->download((string) $run->artifact_path);
    }
}

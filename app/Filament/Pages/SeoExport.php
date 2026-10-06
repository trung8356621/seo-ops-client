<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Jobs\ClientTransfer\BuildRetryDataPackageJob;
use App\Jobs\ClientTransfer\PrepareSeoExportJob;
use App\Models\ClientTransferRun;
use App\Models\User;
use App\Services\ClientTransfer\FailureRequestInspector;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class SeoExport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $slug = 'services/seo/export';

    protected static string $view = 'filament.pages.seo-export';

    protected static bool $shouldRegisterNavigation = false;

    public ?string $runId = null;

    /** @var array<string, mixed>|null */
    public ?array $retryData = [];

    public ?string $failurePackagePath = null;

    /** @var array<string, mixed>|null */
    public ?array $failureInspection = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            FileUpload::make('failure_package')
                ->label('Upload Failure ZIP')
                ->disk('local')
                ->directory('client-transfer/uploads')
                ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                ->maxSize(204800)
                ->required(),
        ])->statePath('retryData');
    }

    public function inspectFailurePackage(): void
    {
        $relative = $this->form->getState()['failure_package'] ?? null;
        if (! is_string($relative) || ! Storage::disk('local')->exists($relative)) {
            Notification::make()->title('Failure ZIP not found.')->danger()->send();

            return;
        }
        $this->failurePackagePath = Storage::disk('local')->path($relative);
        try {
            $this->failureInspection = (new FailureRequestInspector)->inspect($this->failurePackagePath);
            Notification::make()->title('Failure package inspected.')->success()->send();
        } catch (\Throwable $e) {
            $this->failureInspection = null;
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function runRetryExport(): void
    {
        if ($this->failureInspection === null || ! is_file((string) $this->failurePackagePath)) {
            return;
        }
        $runId = Str::random(12);
        ClientTransferRun::query()->create([
            'run_id' => $runId, 'type' => 'retry_export', 'status' => 'pending', 'phase' => 'queued', 'started_at' => now(),
            'metadata' => ['original_import_run_id' => $this->failureInspection['original_import_run_id']],
        ]);
        BuildRetryDataPackageJob::dispatch($runId, (string) $this->failurePackagePath)->onQueue('client-transfer');
        Notification::make()->title('Retry data re-export queued.')->info()->send();
    }

    public function getLatestRetryRunProperty(): ?ClientTransferRun
    {
        return ClientTransferRun::query()->where('type', 'retry_export')->latest('id')->first();
    }

    public function downloadRetryDataPackage(): ?BinaryFileResponse
    {
        $run = $this->latestRetryRun;

        return $run?->artifact_path && is_file($run->artifact_path) ? response()->download($run->artifact_path) : null;
    }

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

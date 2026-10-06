<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Jobs\ClientTransfer\PrepareSeoImportJob;
use App\Jobs\ClientTransfer\RollbackSeoImportJob;
use App\Models\ClientTransferRun;
use App\Models\User;
use App\Services\ClientTransfer\ClientTransferImporter;
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

final class SeoImport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $slug = 'services/seo/import';

    protected static string $view = 'filament.pages.seo-import';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public ?string $runId = null;

    public ?string $uploadedFilePath = null;

    public ?string $inspectedFormat = null;

    public ?string $inspectedVersion = null;

    public ?string $inspectedExportedAt = null;

    public ?bool $targetEmpty = null;

    public ?bool $connectionReady = null;

    public ?bool $schemaReady = null;

    /** @var list<string> */
    public array $schemaErrors = [];

    public ?bool $serviceReady = null;

    public ?int $inspectedTotalRecords = null;

    public ?int $inspectedDatasetCount = null;

    public bool $isRetryData = false;

    public ?string $originalImportRunId = null;

    public function getTitle(): string
    {
        return 'SEO Portable Data Import';
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && in_array((string) $user->role, [User::ROLE_OWNER, User::ROLE_ADMIN], true);
    }

    public function mount(): void
    {
        $this->restoreActiveImportRun();
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                FileUpload::make('package_file')
                    ->label('Chọn file gói SEO transfer (.zip)')
                    ->disk('local')
                    ->directory('client-transfer/uploads')
                    ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                    ->maxSize(204800) // 200MB
                    ->required()
                    ->live(),
            ])
            ->statePath('data');
    }

    public function inspectPackage(): void
    {
        $state = $this->form->getState();
        $relativeFile = $state['package_file'] ?? null;

        if (empty($relativeFile)) {
            Notification::make()->title('Vui lòng chọn file gói zip cần import')->warning()->send();

            return;
        }

        $fullPath = $this->resolveUploadedPackagePath((string) $relativeFile);
        if ($fullPath === null) {
            Notification::make()->title('File tải lên không tồn tại')->danger()->send();

            return;
        }

        $this->uploadedFilePath = $fullPath;

        try {
            $importer = new ClientTransferImporter;
            $inspection = $importer->inspect($fullPath);

            $this->inspectedFormat = (string) $inspection['manifest']->format;
            $this->inspectedVersion = (string) $inspection['manifest']->formatVersion;
            $this->inspectedExportedAt = (string) $inspection['manifest']->exportedAt;
            $this->connectionReady = (bool) $inspection['connection_ready'];
            $this->schemaReady = (bool) $inspection['schema_ready'];
            $this->schemaErrors = (array) $inspection['schema_errors'];
            $this->targetEmpty = (bool) $inspection['target_empty'];
            $this->serviceReady = (bool) $inspection['service_ready'];
            $this->inspectedDatasetCount = count($inspection['manifest']->datasets);
            $this->inspectedTotalRecords = array_sum(array_map(fn ($d) => $d->count, $inspection['manifest']->datasets));
            $this->isRetryData = (bool) $inspection['is_retry_data'];
            $this->originalImportRunId = $inspection['original_import_run_id'];

            Notification::make()->title('Kiểm tra gói thành công')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Kiểm tra gói thất bại: '.$e->getMessage())->danger()->send();
        }
    }

    public function runImport(): void
    {
        $activeRun = $this->activeImportRun();
        if ($activeRun !== null) {
            $this->runId = $activeRun->run_id;
            Notification::make()->title('Một tác vụ import đang chạy. Không thể bắt đầu import khác.')->warning()->send();

            return;
        }

        if (empty($this->uploadedFilePath) || ! file_exists($this->uploadedFilePath)) {
            Notification::make()->title('Vui lòng kiểm tra gói dữ liệu trước khi import.')->warning()->send();

            return;
        }

        if (! $this->connectionReady || ! $this->schemaReady || (! $this->isRetryData && ! $this->targetEmpty)) {
            Notification::make()->title('Target import chưa sẵn sàng. Vui lòng kiểm tra kết nối, schema và dữ liệu hiện có.')->danger()->send();

            return;
        }

        $this->runId = Str::random(12);

        ClientTransferRun::query()->create([
            'run_id' => $this->runId,
            'type' => 'import',
            'status' => 'pending',
            'phase' => 'queued',
            'started_at' => now(),
            'metadata' => [
                'uploaded_file' => $this->uploadedFilePath,
                'mode' => $this->isRetryData ? 'retry' : 'full',
                'is_retry' => $this->isRetryData,
                'original_import_run_id' => $this->originalImportRunId,
            ],
        ]);

        PrepareSeoImportJob::dispatch($this->runId, $this->uploadedFilePath)->onQueue('client-transfer');

        Notification::make()
            ->title('Đã đưa tác vụ nhập dữ liệu vào hàng đợi (client-transfer)')
            ->info()
            ->send();
    }

    private function resolveUploadedPackagePath(string $relativeFile): ?string
    {
        $disk = Storage::disk('local');

        return $disk->exists($relativeFile) ? $disk->path($relativeFile) : null;
    }

    public function getRunProperty(): ?ClientTransferRun
    {
        if ($this->runId !== null) {
            $explicitRun = ClientTransferRun::query()
                ->where('type', 'import')
                ->where('run_id', $this->runId)
                ->first();

            if ($explicitRun !== null) {
                return $explicitRun;
            }
        }

        return $this->activeImportRun() ?? $this->latestImportRun();
    }

    public function activeImportRun(): ?ClientTransferRun
    {
        return ClientTransferRun::query()
            ->where('type', 'import')
            ->whereIn('status', ['pending', 'running', 'rolling_back'])
            ->latest('id')
            ->first();
    }

    public function latestImportRun(): ?ClientTransferRun
    {
        return ClientTransferRun::query()
            ->where('type', 'import')
            ->whereIn('status', ['completed', 'failed', 'rolled_back', 'rollback_failed'])
            ->latest('id')
            ->first();
    }

    private function restoreActiveImportRun(): void
    {
        $activeRun = $this->activeImportRun();
        if ($activeRun !== null) {
            $this->runId = $activeRun->run_id;
        }
    }

    public function downloadRetryPackage(): ?BinaryFileResponse
    {
        $run = $this->run;
        if ($run === null || empty($run->retry_package_path)) {
            Notification::make()->title('Không tìm thấy gói retry.')->warning()->send();

            return null;
        }

        $path = (string) $run->retry_package_path;
        if (! file_exists($path)) {
            Notification::make()->title('File gói retry không còn tồn tại trên disk.')->danger()->send();

            return null;
        }

        return response()->download($path);
    }

    public function rollbackImport(): void
    {
        $run = $this->run;
        if ($run === null || ! $run->canRollback()) {
            Notification::make()->title('Run import này không thể rollback.')->warning()->send();

            return;
        }

        $run->update(['status' => 'rolling_back', 'phase' => 'rollback_queued']);
        RollbackSeoImportJob::dispatch($run->run_id)->onQueue('client-transfer');

        Notification::make()->title('Đã đưa rollback vào hàng đợi client-transfer.')->info()->send();
    }
}

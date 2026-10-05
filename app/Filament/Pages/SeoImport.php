<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Jobs\ClientTransfer\PrepareSeoImportJob;
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

    public ?bool $serviceReady = null;

    public ?int $inspectedTotalRecords = null;

    public ?int $inspectedDatasetCount = null;

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

        $fullPath = storage_path('app/'.$relativeFile);
        if (! file_exists($fullPath)) {
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
            $this->targetEmpty = (bool) $inspection['target_empty'];
            $this->serviceReady = (bool) $inspection['service_ready'];
            $this->inspectedDatasetCount = count($inspection['manifest']->datasets);
            $this->inspectedTotalRecords = array_sum(array_map(fn ($d) => $d->count, $inspection['manifest']->datasets));

            Notification::make()->title('Kiểm tra gói thành công')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Kiểm tra gói thất bại: '.$e->getMessage())->danger()->send();
        }
    }

    public function runImport(): void
    {
        if (empty($this->uploadedFilePath) || ! file_exists($this->uploadedFilePath)) {
            Notification::make()->title('Vui lòng kiểm tra gói dữ liệu trước khi import.')->warning()->send();

            return;
        }

        if (! $this->targetEmpty) {
            Notification::make()->title('Database SEO đích có dữ liệu. V1 chỉ hỗ trợ import vào database trống.')->danger()->send();

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
            ],
        ]);

        PrepareSeoImportJob::dispatch($this->runId, $this->uploadedFilePath)->onQueue('client-transfer');

        Notification::make()
            ->title('Đã đưa tác vụ nhập dữ liệu vào hàng đợi (client-transfer)')
            ->info()
            ->send();
    }

    public function getRunProperty(): ?ClientTransferRun
    {
        return $this->runId ? ClientTransferRun::query()->where('run_id', $this->runId)->first() : null;
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
}

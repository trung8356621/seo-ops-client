<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\ClientTransfer\ClientTransferImporter;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
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

    /** @var array<string, mixed>|null */
    public ?array $inspectionResult = null;

    /** @var array<string, mixed>|null */
    public ?array $importResult = null;

    public ?string $uploadedFilePath = null;

    public bool $force = false;

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
                Toggle::make('force')
                    ->label('Bỏ qua kiểm tra database trống (Force Import)')
                    ->helperText('Mặc định V1 từ chối import nếu database SEO mục tiêu đã có dữ liệu.')
                    ->default(false),
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

        $fullPath = storage_path('app/' . $relativeFile);
        if (! file_exists($fullPath)) {
            Notification::make()->title('File tải lên không tồn tại')->danger()->send();
            return;
        }

        $this->uploadedFilePath = $fullPath;
        $this->force = (bool) ($state['force'] ?? false);

        try {
            $importer = new ClientTransferImporter();
            $inspection = $importer->inspect($fullPath);

            $this->inspectionResult = [
                'format' => $inspection['manifest']->format,
                'format_version' => $inspection['manifest']->formatVersion,
                'exported_at' => $inspection['manifest']->exportedAt,
                'source' => $inspection['manifest']->source,
                'datasets' => array_map(fn ($d) => ['count' => $d->count, 'parts' => count($d->parts)], $inspection['manifest']->datasets),
                'target_empty' => $inspection['target_empty'],
                'non_empty_tables' => $inspection['non_empty_tables'],
                'service_ready' => $inspection['service_ready'],
            ];

            Notification::make()->title('Kiểm tra gói thành công')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Kiểm tra gói thất bại: ' . $e->getMessage())->danger()->send();
        }
    }

    public function runImport(): void
    {
        if (empty($this->uploadedFilePath) || ! file_exists($this->uploadedFilePath)) {
            Notification::make()->title('Vui lòng kiểm tra gói dữ liệu trước khi import.')->warning()->send();
            return;
        }

        try {
            $importer = new ClientTransferImporter();
            $this->importResult = $importer->import($this->uploadedFilePath, $this->force);

            if ($this->importResult['total_failed'] > 0) {
                Notification::make()
                    ->title("Import hoàn tất với {$this->importResult['total_failed']} bản ghi lỗi (đã tạo gói retry).")
                    ->warning()
                    ->send();
            } else {
                Notification::make()
                    ->title("Import thành công toàn bộ {$this->importResult['total_imported']} bản ghi!")
                    ->success()
                    ->send();
            }
        } catch (\Throwable $e) {
            Notification::make()->title('Import thất bại: ' . $e->getMessage())->danger()->send();
        }
    }

    public function downloadRetryPackage(): ?BinaryFileResponse
    {
        if (empty($this->importResult['retry_package_path'])) {
            return null;
        }

        $path = (string) $this->importResult['retry_package_path'];
        if (! file_exists($path)) {
            Notification::make()->title('File retry package không tồn tại')->danger()->send();
            return null;
        }

        return response()->download($path);
    }
}

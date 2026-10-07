<?php

declare(strict_types=1);

namespace Tests\Feature\ClientTransfer;

use App\Filament\Pages\SeoImport;
use App\Jobs\ClientTransfer\PrepareSeoImportJob;
use App\Services\ClientTransfer\Support\ClientTransferUploadLimits;
use Illuminate\Support\Facades\Queue;
use Tests\Unit\ClientTransfer\TransferDatabaseTestCase;

final class ClientTransferUploadLimitPageTest extends TransferDatabaseTestCase
{
    public function test_run_import_rejects_package_larger_than_configured_limit(): void
    {
        Queue::fake();
        config()->set('client-transfer.max_upload_mb', 1);

        $packagePath = $this->tempDir.DIRECTORY_SEPARATOR.'too-large.zip';
        file_put_contents($packagePath, str_repeat('z', 1024 * 1024 + 64));
        self::assertTrue(ClientTransferUploadLimits::fileExceedsLimit($packagePath));

        $page = new SeoImport;
        $page->uploadedFilePath = $packagePath;
        $page->connectionReady = true;
        $page->schemaReady = true;
        $page->targetEmpty = true;
        $page->runImport();

        Queue::assertNotPushed(PrepareSeoImportJob::class);
    }

    public function test_run_import_accepts_package_within_configured_limit(): void
    {
        Queue::fake();
        config()->set('client-transfer.max_upload_mb', 2048);

        $packagePath = $this->tempDir.DIRECTORY_SEPARATOR.'ok.zip';
        file_put_contents($packagePath, 'small-package');

        $page = new SeoImport;
        $page->uploadedFilePath = $packagePath;
        $page->connectionReady = true;
        $page->schemaReady = true;
        $page->targetEmpty = true;
        $page->runImport();

        Queue::assertPushed(PrepareSeoImportJob::class);
    }
}

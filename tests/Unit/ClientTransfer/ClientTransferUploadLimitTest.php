<?php

declare(strict_types=1);

namespace Tests\Unit\ClientTransfer;

use App\Filament\Pages\SeoImport;
use App\Services\ClientTransfer\Support\ClientTransferUploadLimits;
use Tests\TestCase;

final class ClientTransferUploadLimitTest extends TestCase
{
    public function test_default_limit_is_2048_mb_shared_as_kilobytes_for_file_upload(): void
    {
        config()->set('client-transfer.max_upload_mb', 2048);

        self::assertSame(2048, ClientTransferUploadLimits::maxUploadMegabytes());
        self::assertSame(2_097_152, ClientTransferUploadLimits::maxUploadKilobytes());
        self::assertSame(2048 * 1024 * 1024, ClientTransferUploadLimits::maxUploadBytes());

        $upload = \Filament\Forms\Components\FileUpload::make('package_file')
            ->maxSize(ClientTransferUploadLimits::maxUploadKilobytes());
        self::assertSame(2_097_152, $upload->getMaxSize());
    }

    public function test_configured_mb_overrides_the_default(): void
    {
        config()->set('client-transfer.max_upload_mb', 512);

        self::assertSame(512, ClientTransferUploadLimits::maxUploadMegabytes());
        self::assertSame(524_288, ClientTransferUploadLimits::maxUploadKilobytes());
        self::assertSame(512, (new SeoImport)->configuredMaxUploadMegabytes());
    }

    public function test_file_exceeds_limit_uses_the_configured_byte_ceiling(): void
    {
        config()->set('client-transfer.max_upload_mb', 1);
        $over = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ct-over-'.bin2hex(random_bytes(4)).'.zip';
        $under = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ct-under-'.bin2hex(random_bytes(4)).'.zip';
        file_put_contents($over, str_repeat('a', 1024 * 1024 + 1));
        file_put_contents($under, str_repeat('b', 1024));

        try {
            self::assertTrue(ClientTransferUploadLimits::fileExceedsLimit($over));
            self::assertFalse(ClientTransferUploadLimits::fileExceedsLimit($under));
        } finally {
            @unlink($over);
            @unlink($under);
        }
    }

    public function test_php_ini_warning_when_upload_limits_are_below_configured_value(): void
    {
        config()->set('client-transfer.max_upload_mb', 2048);

        $warning = ClientTransferUploadLimits::phpIniBottleneckWarning('32M', '32M');

        self::assertNotNull($warning);
        self::assertStringContainsString('CLIENT_TRANSFER_MAX_UPLOAD_MB=2048', $warning);
        self::assertStringContainsString('upload_max_filesize=32M', $warning);
        self::assertStringContainsString('post_max_size=32M', $warning);
        self::assertStringNotContainsString('204.8', $warning);
    }

    public function test_php_ini_warning_is_absent_when_php_allows_the_configured_limit(): void
    {
        config()->set('client-transfer.max_upload_mb', 2048);

        self::assertNull(ClientTransferUploadLimits::phpIniBottleneckWarning('2048M', '2048M'));
        self::assertNull(ClientTransferUploadLimits::phpIniBottleneckWarning('-1', '-1'));
    }

    public function test_livewire_temp_upload_max_is_at_least_the_client_transfer_limit(): void
    {
        $rules = config('livewire.temporary_file_upload.rules');
        $maxRule = null;
        foreach ((array) $rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'max:')) {
                $maxRule = (int) substr($rule, 4);
            }
        }

        self::assertNotNull($maxRule);
        self::assertGreaterThanOrEqual(ClientTransferUploadLimits::maxUploadKilobytes(), $maxRule);
    }

    public function test_parse_ini_size_understands_php_shorthand(): void
    {
        self::assertSame(32 * 1024 * 1024, ClientTransferUploadLimits::parseIniSize('32M'));
        self::assertSame(2 * 1024 * 1024 * 1024, ClientTransferUploadLimits::parseIniSize('2G'));
        self::assertSame(512 * 1024, ClientTransferUploadLimits::parseIniSize('512K'));
        self::assertSame(PHP_INT_MAX, ClientTransferUploadLimits::parseIniSize('-1'));
    }
}

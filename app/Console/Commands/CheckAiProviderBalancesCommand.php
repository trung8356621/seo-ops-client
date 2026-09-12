<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiConnection;
use Illuminate\Console\Command;
use Omnichannel\Addons\AiPrompt\Services\Wallet\AiProviderWalletService;

final class CheckAiProviderBalancesCommand extends Command
{
    protected $signature = 'ai:wallet-check-balance {--connection= : ID of specific connection to check}';

    protected $description = 'Kiểm tra và cập nhật số dư chính thức từ API của AI providers';

    public function handle(AiProviderWalletService $walletService): int
    {
        $connectionId = $this->option('connection');

        if ($connectionId !== null && is_numeric($connectionId)) {
            $connection = ApiConnection::query()->find((int) $connectionId);
            if (! $connection instanceof ApiConnection) {
                $this->error("Không tìm thấy connection với ID {$connectionId}");

                return self::FAILURE;
            }

            $this->info("Đang kiểm tra số dư connection: {$connection->name} ({$connection->provider})...");
            $result = $walletService->checkConnectionBalance($connection);

            if ($result->supported) {
                if ($result->success) {
                    $this->info("Thành công: {$result->currency} {$result->balance}");
                } else {
                    $this->warn("Thất bại: {$result->error}");
                }
            } else {
                $this->line("Provider {$connection->provider} không hỗ trợ balance API.");
            }

            return self::SUCCESS;
        }

        $this->info('Bắt đầu kiểm tra số dư tất cả active AI connections...');
        $results = $walletService->checkAllActiveConnections();
        $this->info(sprintf('Hoàn thành kiểm tra %d connections.', count($results)));

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Control;

use App\Enums\ClientControlStatus;
use App\Models\ClientControlState;
use Illuminate\Support\Facades\Schema;

final class ClientLockGuard
{
    public function isLocked(): bool
    {
        if (! Schema::hasTable('client_control_state')) {
            return false;
        }

        try {
            $state = ClientControlState::query()->orderBy('id')->first();

            return $state instanceof ClientControlState && $state->isLocked();
        } catch (\Throwable) {
            return false;
        }
    }

    public function status(): ClientControlStatus
    {
        if (! Schema::hasTable('client_control_state')) {
            return ClientControlStatus::Unregistered;
        }

        try {
            $state = ClientControlState::query()->orderBy('id')->first();

            return $state instanceof ClientControlState
                ? $state->status
                : ClientControlStatus::Unregistered;
        } catch (\Throwable) {
            return ClientControlStatus::Unregistered;
        }
    }

    public function publicMessage(): string
    {
        return __('client_control.locked_message');
    }
}

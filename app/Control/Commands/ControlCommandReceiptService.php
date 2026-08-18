<?php

declare(strict_types=1);

namespace App\Control\Commands;

use App\Control\Signing\ControlRequestSigner;
use App\Enums\ClientControlCommandStatus;
use App\Models\ClientControlCommand;
use App\Models\ClientControlState;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

final class ControlCommandReceiptService
{
    public function __construct(
        private readonly ControlCommandDispatcher $dispatcher,
        private readonly ControlRequestSigner $signer,
    ) {}

    public function handle(ControlCommandEnvelope $envelope): ControlCommandResult
    {
        $payloadHash = $this->signer->payloadHash($envelope->payload);

        $existing = ClientControlCommand::query()
            ->where('command_id', $envelope->commandId)
            ->first();

        if ($existing instanceof ClientControlCommand) {
            return $this->resultFromReceipt($existing);
        }

        try {
            $receipt = ClientControlCommand::query()->create([
                'command_id' => $envelope->commandId,
                'command' => $envelope->command,
                'payload_hash' => $payloadHash,
                'status' => ClientControlCommandStatus::Received,
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = ClientControlCommand::query()
                ->where('command_id', $envelope->commandId)
                ->first();

            if ($existing instanceof ClientControlCommand) {
                return $this->resultFromReceipt($existing);
            }

            return ControlCommandResult::failed('duplicate_command');
        }

        $this->rememberLastCommand($envelope);

        $receipt->status = ClientControlCommandStatus::Processing;
        $receipt->started_at = now();
        $receipt->save();

        try {
            $outcome = $this->dispatcher->dispatch($envelope->command, $envelope->payload);
        } catch (Throwable $e) {
            $outcome = ControlCommandResult::failed('command_failed');
        }

        $receipt->status = $outcome->status;
        $receipt->result = $outcome->result;
        $receipt->error = $outcome->error;
        $receipt->completed_at = now();
        $receipt->save();

        return $outcome;
    }

    private function rememberLastCommand(ControlCommandEnvelope $envelope): void
    {
        $state = ClientControlState::query()->orderBy('id')->first();
        if (! $state instanceof ClientControlState) {
            return;
        }

        $state->last_command_id = $envelope->commandId;
        $state->last_command_at = now();
        $state->client_version = (string) config('client_control.client_version');
        $state->save();
    }

    private function resultFromReceipt(ClientControlCommand $receipt): ControlCommandResult
    {
        return new ControlCommandResult(
            status: $receipt->status,
            result: is_array($receipt->result) ? $receipt->result : null,
            error: $receipt->error,
        );
    }
}

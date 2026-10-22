<?php

declare(strict_types=1);

namespace App\Core\Installation;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class AdminAccountCreator
{
    public function __construct(
        private readonly InstallationStateManager $stateManager,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, message: string, user_id?: int}
     */
    public function createAdmin(array $input): array
    {
        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255', 'min:2'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'name.required' => 'Họ tên là bắt buộc.',
            'email.required' => 'Email là bắt buộc.',
            'email.email' => 'Email không đúng định dạng.',
            'password.required' => 'Mật khẩu là bắt buộc.',
            'password.min' => 'Mật khẩu phải có ít nhất 8 ký tự.',
            'password.confirmed' => 'Xác nhận mật khẩu không khớp.',
        ]);

        if ($validator->fails()) {
            return [
                'ok' => false,
                'message' => implode(' ', $validator->errors()->all()),
            ];
        }

        try {
            // Prevent duplicate administrator creation
            $existingAdmin = User::query()
                ->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN])
                ->where('is_system', false)
                ->exists();

            if ($existingAdmin) {
                return [
                    'ok' => false,
                    'message' => 'An administrator account already exists. Duplicate setup is not permitted.',
                ];
            }

            $user = User::create([
                'name' => trim((string) $input['name']),
                'email' => strtolower(trim((string) $input['email'])),
                'password' => Hash::make((string) $input['password']),
                'role' => User::ROLE_OWNER,
                'status' => User::STATUS_NORMAL,
                'email_verified_at' => now(),
            ]);

            // Clear password from input memory
            unset($input['password'], $input['password_confirmation']);

            $current = $this->stateManager->readRecord() ?? [];
            $this->stateManager->saveRecord(array_merge($current, [
                'status' => InstallationState::CONFIGURING,
                'step' => 'finish',
                'admin_email' => $user->email,
                'updated_at' => date('c'),
            ]));

            return [
                'ok' => true,
                'message' => 'Administrator account created successfully.',
                'user_id' => (int) $user->id,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Failed to create administrator account: '.$e->getMessage(),
            ];
        }
    }
}

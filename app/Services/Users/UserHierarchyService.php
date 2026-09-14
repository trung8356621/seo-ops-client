<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Core account hierarchy: Owner → Staff (via parent_id = owner scope).
 *
 * Organizational Manager is NOT a Core role. Addon ranks (seo.manager, …) live in Spatie.
 */
final class UserHierarchyService
{
    /**
     * Normalize + validate hierarchy fields for create/update.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalizeFormData(array $data, ?User $existing = null, ?User $actor = null): array
    {
        if (func_num_args() < 3) {
            $actor = auth()->user();
        }

        $role = (string) ($data['role'] ?? $existing?->role ?? '');

        // Legacy org-manager submissions → staff (addon roles are separate).
        if ($role === User::ROLE_MANAGER) {
            $role = User::ROLE_STAFF;
            $data['role'] = User::ROLE_STAFF;
        }

        $parentId = isset($data['parent_id']) && $data['parent_id'] !== '' && $data['parent_id'] !== null
            ? (int) $data['parent_id']
            : null;

        // manager_id is deprecated — never persist new org-manager links.
        $data['manager_id'] = null;

        if ($role === User::ROLE_OWNER) {
            $parentId = null;
        } elseif ($role !== User::ROLE_STAFF) {
            throw ValidationException::withMessages([
                'role' => 'Role không hợp lệ. Core chỉ hỗ trợ owner|staff.',
            ]);
        }

        // Owner actor chỉ được gán Staff vào team của mình.
        if (
            $actor instanceof User
            && (string) $actor->role === User::ROLE_OWNER
            && $role === User::ROLE_STAFF
        ) {
            $parentId = (int) $actor->id;
        }

        if ($role === User::ROLE_STAFF && $parentId !== null && $parentId > 0) {
            $this->assertIsOwner($parentId);
        }

        if ($existing instanceof User) {
            $this->assertSafeRoleChange($existing, $role);
        }

        if ($actor instanceof User && (string) $actor->role === User::ROLE_OWNER) {
            if ($parentId !== null && $parentId !== (int) $actor->id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Owner chỉ được quản lý team của chính mình.',
                ]);
            }
        }

        $data['parent_id'] = $parentId;
        $data['manager_id'] = null;

        return $data;
    }

    public function assertCanDelete(User $user): void
    {
        if ($user->isSystemUser()) {
            throw ValidationException::withMessages([
                'role' => 'Không thể xóa tài khoản hệ thống.',
            ]);
        }

        if ((string) $user->role === User::ROLE_OWNER) {
            $hasTeam = User::query()
                ->where('parent_id', $user->id)
                ->where('role', User::ROLE_STAFF)
                ->exists();
            if ($hasTeam) {
                throw ValidationException::withMessages([
                    'role' => 'Owner đang có Staff. Hãy chuyển team sang Owner khác trước khi xóa.',
                ]);
            }
        }
    }

    /**
     * @deprecated No-op — org Manager hierarchy removed.
     */
    public function detachStaffFromManager(User $manager): void
    {
        // Intentionally empty.
    }

    /**
     * @deprecated No-op — org Manager hierarchy removed.
     */
    public function handleManagerRoleChange(User $user, string $newRole): void
    {
        // Intentionally empty.
    }

    private function assertIsOwner(int $ownerId): void
    {
        $owner = User::query()->find($ownerId);
        if (! $owner instanceof User || (string) $owner->role !== User::ROLE_OWNER) {
            throw ValidationException::withMessages([
                'parent_id' => 'Owner được chọn không hợp lệ.',
            ]);
        }
    }

    private function assertSafeRoleChange(User $existing, string $newRole): void
    {
        $oldRole = (string) $existing->role;
        if ($oldRole === $newRole) {
            return;
        }

        if ($oldRole === User::ROLE_OWNER && $newRole !== User::ROLE_OWNER) {
            $hasTeam = User::query()
                ->where('parent_id', $existing->id)
                ->where('role', User::ROLE_STAFF)
                ->exists();
            if ($hasTeam) {
                throw ValidationException::withMessages([
                    'role' => 'Owner đang có team. Hãy reassignment trước khi đổi role.',
                ]);
            }
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function ownersForSelect(?User $actor = null)
    {
        $actor ??= auth()->user();
        $query = User::query()->where('role', User::ROLE_OWNER)->orderBy('name');

        if ($actor instanceof User && (string) $actor->role === User::ROLE_OWNER) {
            $query->whereKey($actor->id);
        }

        return $query->get(['id', 'name', 'email']);
    }

    /**
     * @deprecated Org Manager select removed.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function managersForOwner(?int $ownerId)
    {
        return collect();
    }
}

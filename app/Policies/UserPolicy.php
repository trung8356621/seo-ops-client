<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return (string) $user->role === User::ROLE_OWNER;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        return $this->ownsOrSelf($user, $model);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return (string) $user->role === User::ROLE_OWNER;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        return $this->ownsOrSelf($user, $model);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        return (string) $user->role === User::ROLE_OWNER
            && (int) $model->id !== (int) $user->id
            && (int) $model->parent_id === (int) $user->id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return $this->delete($user, $model);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return $this->delete($user, $model);
    }

    private function ownsOrSelf(User $actor, User $model): bool
    {
        if ((string) $actor->role !== User::ROLE_OWNER) {
            return false;
        }

        return (int) $model->id === (int) $actor->id
            || (int) $model->parent_id === (int) $actor->id;
    }
}

<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Core\Members\MembersSectionRegistry;
use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\Users\UserHierarchyService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Hash;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** @var array<string, mixed> */
    private array $addonFormState = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = app(UserHierarchyService::class)->normalizeFormData($data);

        $role = (string) ($data['role'] ?? '');
        if (
            (string) (auth()->user()?->role ?? '') === User::ROLE_OWNER
            && $role === User::ROLE_STAFF
        ) {
            $data['parent_id'] = auth()->id();
            $data['manager_id'] = null;
        }

        $data['password'] = Hash::make((string) ($data['password'] ?? UserResource::generateRandomPassword()));

        $this->addonFormState = array_merge(
            is_array($this->data) ? $this->data : [],
            $data,
        );

        foreach (app(MembersSectionRegistry::class)->formOnlyStateKeys() as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var User $record */
        $record = $this->getRecord();
        app(MembersSectionRegistry::class)->afterUserSaved($record, $this->addonFormState);
    }
}

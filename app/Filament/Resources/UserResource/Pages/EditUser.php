<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Core\Members\MembersSectionRegistry;
use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\Users\UserHierarchyService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Hash;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var array<string, mixed> */
    private array $addonFormState = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (): void {
                    /** @var User $record */
                    $record = $this->getRecord();
                    app(UserHierarchyService::class)->assertCanDelete($record);
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['password'] = '';

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var User $record */
        $record = $this->getRecord();
        $hierarchy = app(UserHierarchyService::class);

        $newRole = (string) ($data['role'] ?? $record->role);
        $data = $hierarchy->normalizeFormData($data, $record);

        if (
            (string) (auth()->user()?->role ?? '') === User::ROLE_OWNER
            && $newRole === User::ROLE_STAFF
        ) {
            $data['parent_id'] = auth()->id();
            $data['manager_id'] = null;
        }

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make((string) $data['password']);
        }

        $this->addonFormState = is_array($this->data) ? $this->data : [];

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var User $record */
        $record = $this->getRecord();
        app(MembersSectionRegistry::class)->afterUserSaved($record, $this->addonFormState);
    }
}

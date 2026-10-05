<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Models\User;
use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class UsersDataset extends BaseDataset
{
    public function key(): string
    {
        return 'users';
    }

    public function relativeSubdir(): string
    {
        return 'core/users';
    }

    public function dependencies(): array
    {
        return [];
    }

    protected function queryForExport(): Builder|Relation
    {
        return User::query();
    }

    protected function mapRecordForExport(mixed $row, BlobManager $blobs): ?array
    {
        return [
            'ref' => 'user:'.$row->id,
            'name' => (string) $row->name,
            'email' => (string) $row->email,
            'role' => (string) ($row->role ?? User::ROLE_STAFF),
            'status' => (string) ($row->status ?? User::STATUS_NORMAL),
            'is_system' => (bool) ($row->is_system ?? false),
            'parent_ref' => $row->parent_id ? ('user:'.$row->parent_id) : null,
        ];
    }

    public function importRecord(
        array $record,
        ReferenceMap $refMap,
        ImportRun $run,
        BlobManager $blobs,
        string $partFile,
        int $recordIndex,
    ): void {
        $ref = (string) ($record['ref'] ?? '');
        $email = trim((string) ($record['email'] ?? ''));

        if ($email === '') {
            $run->recordFailed('users', $ref, 'VALIDATION', 'User email is required.', $partFile, $recordIndex, rawRecord: $record);

            return;
        }

        try {
            $user = User::query()->where('email', $email)->first();
            if ($user instanceof User) {
                $refMap->set($ref, 'user', (int) $user->id);
                $run->recordImported('users', $ref, $partFile, $recordIndex);

                if (! empty($record['parent_ref'])) {
                    $refMap->addDeferred('users', (int) $user->id, 'parent_id', (string) $record['parent_ref']);
                }

                return;
            }

            $newUser = new User;
            $newUser->name = (string) ($record['name'] ?? $email);
            $newUser->email = $email;
            $newUser->role = (string) ($record['role'] ?? User::ROLE_STAFF);
            $newUser->status = (string) ($record['status'] ?? User::STATUS_NORMAL);
            $newUser->is_system = (bool) ($record['is_system'] ?? false);
            $newUser->password = Hash::make(Str::random(32));
            $newUser->save();
            $refMap->trackCreated($this->key(), (int) $newUser->id);

            $refMap->set($ref, 'user', (int) $newUser->id);
            $run->recordImported('users', $ref, $partFile, $recordIndex);

            if (! empty($record['parent_ref'])) {
                $refMap->addDeferred('users', (int) $newUser->id, 'parent_id', (string) $record['parent_ref']);
            }
        } catch (\Throwable $e) {
            $run->recordFailed('users', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }

    public function resolveDeferred(ReferenceMap $refMap, ImportRun $run): void
    {
        $deferred = $refMap->getDeferredReferences();
        foreach ($deferred as $item) {
            if ($item['entity_type'] !== 'users' || $item['field_name'] !== 'parent_id') {
                continue;
            }

            $targetParentId = $refMap->get($item['target_ref']);
            if ($targetParentId !== null && $targetParentId > 0) {
                User::query()->where('id', $item['target_id'])->update(['parent_id' => $targetParentId]);
            } else {
                $run->recordWarning('users', 'user:'.$item['target_id'], "Unresolved deferred parent user ref [{$item['target_ref']}]", isMissingRef: true);
            }
        }
    }
}

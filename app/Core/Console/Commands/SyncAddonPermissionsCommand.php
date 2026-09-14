<?php

declare(strict_types=1);

namespace App\Core\Console\Commands;

use App\Core\Permissions\AddonPermissionRegistry;
use App\Core\Permissions\LegacySeoRoleBridge;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent permission foundation sync + legacy backfill.
 */
final class SyncAddonPermissionsCommand extends Command
{
    protected $signature = 'permissions:sync-addons
        {--backfill-seo : Backfill users.seo_role → Spatie seo.* roles}
        {--demote-org-managers : Convert legacy users.role=manager → staff}';

    protected $description = 'Sync addon Spatie roles/permissions and optionally backfill legacy SEO roles';

    public function handle(
        AddonPermissionRegistry $registry,
        LegacySeoRoleBridge $seoBridge,
    ): int {
        if (! $registry->permissionTablesReady()) {
            $this->error('Spatie permission tables are missing. Run migrations first.');

            return self::FAILURE;
        }

        $created = $registry->syncToDatabase();
        $this->info(sprintf(
            'Synced addon catalog: roles_created=%d permissions_created=%d',
            $created['roles_created'],
            $created['permissions_created'],
        ));

        if ($this->option('backfill-seo')) {
            $result = $seoBridge->backfillAll();
            $this->info(sprintf(
                'SEO backfill: scanned=%d assigned=%d skipped=%d',
                $result['scanned'],
                $result['assigned'],
                $result['skipped'],
            ));
        }

        if ($this->option('demote-org-managers')) {
            $this->demoteOrgManagers();
        }

        return self::SUCCESS;
    }

    private function demoteOrgManagers(): void
    {
        $connection = (string) config('database.core_connection', config('database.default'));
        if (! Schema::connection($connection)->hasTable('users')) {
            $this->warn('users table missing; skip demote.');

            return;
        }

        $updated = DB::connection($connection)
            ->table('users')
            ->where('role', 'manager')
            ->update(['role' => User::ROLE_STAFF]);

        $this->info(sprintf('Demoted org manager rows → staff: %d', $updated));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\ClientTransfer;

use App\Filament\Pages\SeoExport;
use App\Jobs\ClientTransfer\FinalizeSeoExportJob;
use App\Jobs\ClientTransfer\PrepareSeoExportJob;
use App\Models\ClientTransferRun;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Unit\ClientTransfer\TransferDatabaseTestCase;

final class ExportArtifactLifecycleTest extends TransferDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolate all export artifacts from the real storage directory.
        $this->app->useStoragePath($this->tempDir.DIRECTORY_SEPARATOR.'storage');
        mkdir(storage_path('app/client-transfer'), 0755, true);
    }

    private function runExport(string $id): ClientTransferRun
    {
        $run = ClientTransferRun::query()->create([
            'run_id' => $id,
            'type' => 'export',
            'status' => 'pending',
            'phase' => 'queued',
            'started_at' => now(),
        ]);
        PrepareSeoExportJob::dispatchSync($id);

        return $run->refresh();
    }

    private function seedData(): void
    {
        $user = User::query()->create(['name' => 'Exp', 'email' => 'exp@test.test']);
        Site::query()->create(['domain' => 'exp.test', 'user_id' => $user->id, 'status' => 'active']);
    }

    private function owner(): User
    {
        $u = User::query()->create(['name' => 'Owner', 'email' => 'owner@export.test']);
        $u->role = User::ROLE_OWNER;
        $u->save();

        return $u;
    }

    public function test_completed_export_is_visible_to_a_fresh_page_instance_and_downloadable_without_run_id(): void
    {
        $this->seedData();
        $run = $this->runExport('exp-a');
        self::assertTrue($run->isCompleted());
        self::assertSame(FinalizeSeoExportJob::canonicalPath(), $run->artifact_path);

        $this->actingAs($this->owner());

        $page = Livewire::test(SeoExport::class); // brand new component, no runId
        self::assertNull($page->get('runId'));
        $page->assertSee('Latest Export')->assertSee('Regenerate');
        $page->call('downloadPackage')->assertFileDownloaded();
    }

    public function test_successful_regeneration_replaces_latest_and_cleans_temp_and_staging(): void
    {
        $this->seedData();
        $this->runExport('exp-1');
        $first = filemtime(FinalizeSeoExportJob::canonicalPath());
        clearstatcache();
        sleep(1);

        $second = $this->runExport('exp-2');

        self::assertTrue($second->isCompleted());
        clearstatcache();
        self::assertGreaterThan($first, filemtime(FinalizeSeoExportJob::canonicalPath()));

        $exports = glob(storage_path('app/client-transfer/exports').DIRECTORY_SEPARATOR.'*');
        self::assertSame([FinalizeSeoExportJob::canonicalPath()], $exports, 'Only the canonical ZIP may remain.');
        self::assertDirectoryDoesNotExist(storage_path('app/client-transfer/staging_export_exp-2'));
        self::assertDirectoryDoesNotExist(storage_path('app/client-transfer/staging_export_exp-1'));
    }

    public function test_failed_regeneration_preserves_previous_artifact(): void
    {
        $this->seedData();
        $this->runExport('exp-good');
        $path = FinalizeSeoExportJob::canonicalPath();
        $before = hash_file('sha256', $path);

        ClientTransferRun::query()->create([
            'run_id' => 'exp-bad',
            'type' => 'export',
            'status' => 'running',
            'phase' => 'export:users',
        ]);
        // No staging directory => finalization fails before canonical replacement.
        $job = new FinalizeSeoExportJob('exp-bad');
        try {
            $job->handle();
            self::fail('Finalization should have failed.');
        } catch (\Throwable $e) {
            $job->failed($e);
        }

        self::assertTrue(ClientTransferRun::query()->where('run_id', 'exp-bad')->first()->isFailed());
        self::assertFileExists($path);
        self::assertSame($before, hash_file('sha256', $path));

        $this->actingAs($this->owner());
        Livewire::test(SeoExport::class)->call('downloadPackage')->assertFileDownloaded();
    }

    public function test_regeneration_does_not_touch_old_zip_before_new_one_is_finalized(): void
    {
        $this->seedData();
        $this->runExport('exp-old');
        $path = FinalizeSeoExportJob::canonicalPath();
        $before = hash_file('sha256', $path);

        // Queue a new run but do not execute any job yet: old ZIP must be untouched and still listed.
        Bus::fake();
        $this->actingAs($this->owner());
        Livewire::test(SeoExport::class)->call('runExport')->assertSee('Latest Export');

        self::assertFileExists($path);
        self::assertSame($before, hash_file('sha256', $path));
    }

    public function test_duplicate_start_while_running_does_not_dispatch_another_export(): void
    {
        Bus::fake();
        $this->actingAs($this->owner());

        $page = Livewire::test(SeoExport::class);
        $page->call('runExport');
        $page->call('runExport');

        Bus::assertDispatchedTimes(PrepareSeoExportJob::class, 1);
        self::assertSame(1, ClientTransferRun::query()->where('type', 'export')->count());
    }
}

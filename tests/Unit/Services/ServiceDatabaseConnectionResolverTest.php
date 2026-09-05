<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Service;
use App\Models\ServiceDatabaseConnection;
use App\Services\ServiceDatabaseConnectionResolver;
use App\Services\ServiceDatabasePasswordIntent;
use App\Services\ServiceIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ServiceDatabaseConnectionResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('service_database_connections');
        Schema::dropIfExists('services');

        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('addon_namespace')->nullable();
            $table->string('db_connection')->default('mysql');
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable();
            $table->text('service_key')->nullable();
            $table->timestamps();
        });

        Schema::create('service_database_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('service_id')->unique();
            $table->string('type', 16)->default('manual');
            $table->string('driver', 32)->default('mysql');
            $table->string('host')->nullable();
            $table->string('port', 16)->nullable();
            $table->string('database')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function test_one_connection_max_per_service_via_upsert(): void
    {
        $seo = $this->makeService('seo-content-ai', 'omi_seo_ai');
        $resolver = app(ServiceDatabaseConnectionResolver::class);

        $resolver->upsert($seo, $this->attrs('omi_seo_ai'), $this->setPassword('pass-1'));
        $resolver->upsert($seo, $this->attrs('omi_seo_ai'), $this->setPassword('pass-2'));

        self::assertSame(1, ServiceDatabaseConnection::query()->where('service_id', $seo->id)->count());
        self::assertSame('pass-2', ServiceDatabaseConnection::query()->where('service_id', $seo->id)->first()?->password);
    }

    public function test_empty_password_can_be_saved_on_create(): void
    {
        $seeding = $this->makeService('seeding', 'omi_seeding');
        $resolver = app(ServiceDatabaseConnectionResolver::class);

        $row = $resolver->upsert(
            $seeding,
            $this->attrs('omi_seeding', 'root'),
            ServiceDatabasePasswordIntent::fromFormState(['password' => '', 'clear_password' => false], false),
        );

        self::assertNull($row->password);
        self::assertSame('root', $row->username);
        self::assertSame('omi_seeding', $row->database);

        $config = $resolver->buildConfig($row);
        self::assertSame('', $config['password']);
    }

    public function test_blank_edit_keeps_existing_password(): void
    {
        $seeding = $this->makeService('seeding', 'omi_seeding');
        $resolver = app(ServiceDatabaseConnectionResolver::class);
        $resolver->upsert($seeding, $this->attrs('omi_seeding'), $this->setPassword('secret'));

        $resolver->upsert(
            $seeding,
            $this->attrs('omi_seeding'),
            ServiceDatabasePasswordIntent::fromFormState(['password' => '', 'clear_password' => false], true),
        );

        self::assertSame('secret', ServiceDatabaseConnection::query()->where('service_id', $seeding->id)->first()?->password);
    }

    public function test_clear_password_flag_nulls_stored_password(): void
    {
        $seeding = $this->makeService('seeding', 'omi_seeding');
        $resolver = app(ServiceDatabaseConnectionResolver::class);
        $resolver->upsert($seeding, $this->attrs('omi_seeding'), $this->setPassword('secret'));

        $resolver->upsert(
            $seeding,
            $this->attrs('omi_seeding'),
            ServiceDatabasePasswordIntent::fromFormState(['password' => '', 'clear_password' => true], true),
        );

        self::assertNull(ServiceDatabaseConnection::query()->where('service_id', $seeding->id)->first()?->password);
    }

    public function test_form_draft_test_has_no_env_fallback_and_accepts_empty_password(): void
    {
        $resolver = app(ServiceDatabaseConnectionResolver::class);
        // Should attempt exact draft (may fail if MySQL unreachable — but must not silently succeed via env).
        try {
            $resolver->testDraftAttributes([
                'host' => '127.0.0.1',
                'port' => '1',
                'database' => 'omi_seeding_definitely_missing',
                'username' => 'no_such_user_xyz',
            ], '');
            self::fail('Expected unreachable draft to throw');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Không kết nối được', $e->getMessage());
        }
    }

    public function test_canonical_health_source_never_legacy_or_env_when_missing_row(): void
    {
        $this->makeService('seeding', 'omi_seeding');
        $health = app(ServiceDatabaseConnectionResolver::class)->healthReport('seeding');

        self::assertFalse($health['database_configured']);
        self::assertSame('unavailable', $health['connection_source']);
        self::assertSame('Chưa cấu hình', $health['readiness_label']);
    }

    public function test_migrated_ciphertext_is_not_double_encrypted(): void
    {
        $seo = $this->makeService('seo-content-ai', 'omi_seo_ai');
        $cipher = Crypt::encryptString('legacy-plain-pass');

        // Simulate migration copy: raw ciphertext inserted via query builder.
        DB::table('service_database_connections')->insert([
            'service_id' => $seo->id,
            'type' => 'manual',
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'omi_seo_ai',
            'username' => 'omi_seo_ai',
            'password' => $cipher,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = ServiceDatabaseConnection::query()->where('service_id', $seo->id)->first();
        self::assertSame('legacy-plain-pass', $row?->password);

        $raw = DB::table('service_database_connections')->where('service_id', $seo->id)->value('password');
        self::assertSame($cipher, $raw);
        self::assertNotSame('legacy-plain-pass', $raw);
    }

    public function test_password_encrypted_and_logical_connections_isolated(): void
    {
        $seo = $this->makeService('seo-content-ai', 'omi_seo_ai');
        $seeding = $this->makeService('seeding', 'omi_seeding');
        $resolver = app(ServiceDatabaseConnectionResolver::class);

        $resolver->upsert($seo, $this->attrs('omi_seo_ai', 'u'), $this->setPassword('seo-pass'));
        $resolver->upsert($seeding, $this->attrs('omi_seeding', 'u'), $this->setPassword('seed-pass'));

        $raw = DB::table('service_database_connections')->where('service_id', $seo->id)->value('password');
        self::assertNotSame('seo-pass', $raw);

        self::assertSame('omi_seo_ai', $resolver->resolve(ServiceIdentity::PUBLIC_SEO)?->database);
        self::assertSame('omi_seeding', $resolver->resolve(ServiceIdentity::PUBLIC_SEEDING)?->database);

        $resolver->bootstrap('seo', true);
        self::assertSame('omi_seo_ai', config('database.connections.omi_seo_ai.database'));
        $resolver->bootstrap('seeding', true);
        self::assertSame('omi_seeding', config('database.connections.omi_seeding.database'));
    }

    /**
     * @return array<string, mixed>
     */
    private function attrs(string $database, string $username = 'root'): array
    {
        return [
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => $database,
            'username' => $username,
            'is_active' => true,
            'type' => 'manual',
        ];
    }

    /**
     * @return array{action: string, plain: ?string}
     */
    private function setPassword(string $plain): array
    {
        return ['action' => ServiceDatabasePasswordIntent::ACTION_SET, 'plain' => $plain];
    }

    private function makeService(string $slug, string $dbConnection): Service
    {
        return Service::query()->create([
            'name' => $slug,
            'slug' => $slug,
            'addon_namespace' => 'x',
            'db_connection' => $dbConnection,
            'is_active' => true,
            'config' => [],
            'service_key' => 'k-'.$slug,
        ]);
    }
}

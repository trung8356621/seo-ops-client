<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class UserCanonicalDisplayNameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
        $this->createUserTables();
    }

    public function test_editing_display_name_updates_users_name(): void
    {
        $user = User::query()->create([
            'name' => 'Nguyễn Văn A',
            'email' => 'natoli-a@example.com',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_STAFF,
            'status' => User::STATUS_NORMAL,
        ]);

        $user->update([
            'name' => 'Natoli A',
        ]);

        $user->refresh();

        $this->assertSame('Natoli A', $user->name);
        $this->assertSame('Natoli A', $user->display_name);
    }

    public function test_legacy_nickname_meta_is_ignored_for_display_and_not_deleted(): void
    {
        $user = User::query()->create([
            'name' => 'Nguyễn Văn A',
            'email' => 'legacy-nick@example.com',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_STAFF,
            'status' => User::STATUS_NORMAL,
        ]);

        $user->setMeta('nickname', 'Natoli A');
        $user->refresh();

        $this->assertSame('Nguyễn Văn A', $user->name);
        $this->assertSame('Nguyễn Văn A', $user->display_name);
        $this->assertSame('Natoli A', $user->getMeta('nickname'));
    }

    public function test_two_users_with_the_same_display_name_keep_distinct_ids(): void
    {
        $first = User::query()->create([
            'name' => 'Natoli A',
            'email' => 'same-name-1@example.com',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_STAFF,
            'status' => User::STATUS_NORMAL,
        ]);

        $second = User::query()->create([
            'name' => 'Natoli A',
            'email' => 'same-name-2@example.com',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_STAFF,
            'status' => User::STATUS_NORMAL,
        ]);

        $this->assertSame('Natoli A', $first->name);
        $this->assertSame('Natoli A', $second->name);
        $this->assertNotSame((int) $first->id, (int) $second->id);
    }

    private function createUserTables(): void
    {
        $connection = (string) config('database.core_connection', 'sqlite');

        Schema::connection($connection)->dropIfExists('user_meta');
        Schema::connection($connection)->dropIfExists('users');

        Schema::connection($connection)->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('google_id')->nullable();
            $table->string('avatar')->nullable();
            $table->string('role')->nullable();
            $table->string('seo_role')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::connection($connection)->create('user_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
            $table->timestamps();
        });
    }
}

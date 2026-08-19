<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Services\Auth\GoogleLoginUserService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class GoogleLoginUserServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
        $this->createUserTables();
    }

    public function test_first_google_login_initializes_users_name_from_google(): void
    {
        $user = app(GoogleLoginUserService::class)->provision(
            email: 'nguyenvana@example.com',
            googleName: 'Nguyễn Văn A',
            googleId: 'google-1',
            avatar: 'https://example.com/a.png',
        );

        $this->assertSame('Nguyễn Văn A', $user->name);
        $this->assertSame('Nguyễn Văn A', $user->display_name);
        $this->assertSame('nguyenvana@example.com', $user->email);
        $this->assertSame('google-1', $user->google_id);
        $this->assertDatabaseHas('users', [
            'email' => 'nguyenvana@example.com',
            'name' => 'Nguyễn Văn A',
        ]);
    }

    public function test_later_google_login_does_not_overwrite_custom_users_name(): void
    {
        $existing = User::query()->create([
            'name' => 'Natoli A',
            'email' => 'nguyenvana@example.com',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_STAFF,
            'status' => User::STATUS_NORMAL,
            'google_id' => 'google-1',
        ]);

        $user = app(GoogleLoginUserService::class)->provision(
            email: 'nguyenvana@example.com',
            googleName: 'Nguyễn Văn A',
            googleId: 'google-1',
            avatar: 'https://example.com/a.png',
        );

        $this->assertSame((int) $existing->id, (int) $user->id);
        $this->assertSame('Natoli A', $user->fresh()->name);
        $this->assertSame('Natoli A', $user->display_name);
        $this->assertSame('google-1', $user->google_id);
        $this->assertSame('https://example.com/a.png', $user->avatar);
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

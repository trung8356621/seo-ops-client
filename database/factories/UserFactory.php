<?php

namespace Database\Factories;

use App\Core\Permissions\SeoRoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => 'owner',
            'status' => 'normal',
            'parent_id' => null,
        ];
    }

    /**
     * Assign exclusive SEO Spatie role after create.
     */
    public function withSeoRole(string $shortOrSpatie): static
    {
        return $this->afterCreating(function (User $user) use ($shortOrSpatie): void {
            try {
                app(SeoRoleAssignment::class)->assign($user, $shortOrSpatie);
            } catch (\Throwable) {
                // Permission tables may be absent in isolated unit schemas.
            }
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}

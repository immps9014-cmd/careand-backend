<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '010' . fake()->unique()->numerify('########'),
            'role' => 'guardian',
            'password' => static::$password ??= Hash::make('password'),
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
            'status' => 'active',
            'remember_token' => Str::random(10),
        ];
    }

    public function guardian(): static
    {
        return $this->state(fn () => ['role' => 'guardian']);
    }

    public function caregiver(): static
    {
        return $this->state(fn () => ['role' => 'caregiver']);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'admin']);
    }
}

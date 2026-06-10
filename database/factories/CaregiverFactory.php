<?php

namespace Database\Factories;

use App\Models\Caregiver;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CaregiverFactory extends Factory
{
    protected $model = Caregiver::class;

    public function definition(): array
    {
        return [
            'user_id'             => User::factory()->caregiver(),
            'birth_date'          => now()->subYears(45)->toDateString(),
            'gender'              => 'F',
            'license_no'          => 'YY' . fake()->unique()->numerify('######'),
            'license_issued_at'   => now()->subYears(5)->toDateString(),
            'license_verified_at' => now()->subDays(30),
            'specialties'         => ['치매'],
            'base_address'        => '서울시 강남구 역삼동',
            'base_lat'            => 37.5172,
            'base_lng'            => 127.0473,
            'rating_avg'          => 4.5,
            'rating_count'        => 10,
            'completed_sessions'  => 50,
            'grade_level'         => 3,
            'status'              => 'active',
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending', 'license_verified_at' => null]);
    }
}

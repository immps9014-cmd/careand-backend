<?php

namespace Database\Factories;

use App\Models\Guardian;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GuardianFactory extends Factory
{
    protected $model = Guardian::class;

    public function definition(): array
    {
        return [
            'user_id'         => User::factory()->guardian(),
            'relation'        => '자녀',
            'contact_address' => '서울시 마포구',
        ];
    }
}

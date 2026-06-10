<?php

namespace Database\Factories;

use App\Models\Guardian;
use App\Models\Senior;
use Illuminate\Database\Eloquent\Factories\Factory;

class SeniorFactory extends Factory
{
    protected $model = Senior::class;

    public function definition(): array
    {
        return [
            'guardian_id'  => Guardian::factory(),
            'name'         => '홍어머님',
            'birth_date'   => now()->subYears(80)->toDateString(),
            'gender'       => 'F',
            'care_grade'   => '3',
            'diseases'     => ['당뇨'],
            'home_address' => '서울시 마포구',
            'home_lat'     => 37.5663,
            'home_lng'     => 126.9019,
        ];
    }
}

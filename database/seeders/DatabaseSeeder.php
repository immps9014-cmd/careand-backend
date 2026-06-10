<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedServiceCategories();
        $this->seedSuperAdmin();
    }

    private function seedServiceCategories(): void
    {
        $categories = [
            [
                'code' => 'VISIT_CARE',
                'name' => '방문요양',
                'description' => '어르신 자택을 방문하여 일상생활 지원',
                'base_rate' => 18000,
                'is_active' => true,
            ],
            [
                'code' => 'COMPANION',
                'name' => '동행 서비스',
                'description' => '병원·관공서·외출 시 동행',
                'base_rate' => 16000,
                'is_active' => true,
            ],
            [
                'code' => 'NIGHT_CARE',
                'name' => '야간 케어',
                'description' => '저녁~아침 야간 시간대 돌봄 (할증 적용)',
                'base_rate' => 22000,
                'is_active' => true,
            ],
            [
                'code' => 'SHORT_STAY',
                'name' => '단기 보호',
                'description' => '하루 단위 단기 보호 서비스',
                'base_rate' => 20000,
                'is_active' => true,
            ],
            [
                'code' => 'BATH',
                'name' => '방문 목욕',
                'description' => '거동 불편 어르신 방문 목욕',
                'base_rate' => 25000,
                'is_active' => true,
            ],
        ];

        foreach ($categories as $cat) {
            ServiceCategory::updateOrCreate(['code' => $cat['code']], $cat);
        }
    }

    private function seedSuperAdmin(): void
    {
        if (User::where('email', 'admin@careand.co.kr')->exists()) {
            return;
        }

        $user = User::create([
            'email' => 'admin@careand.co.kr',
            'phone' => '01012345678',
            'name' => '슈퍼관리자',
            'role' => 'admin',
            'password' => 'CareandAdmin2026!@',  // 운영 시 반드시 변경
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
            'status' => 'active',
        ]);

        Admin::create([
            'user_id' => $user->id,
            'permission_level' => 'super',
            'department' => '시스템 관리',
        ]);

        $this->command->info('  ✓ 슈퍼관리자 계정 생성: admin@careand.co.kr / CareandAdmin2026!@');
    }
}

<?php

namespace Database\Seeders;

use App\Models\Caregiver;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 추가 전용(additive) 데모 인력 시더 — childcare(아이돌봄)·mental_care(마음돌봄) 도메인 공백 보강.
 *
 * DemoSeeder 는 senior 위주로만 인력을 생성해 홈 'AI추천'이 두 도메인에서 비었다.
 * 이 시더는 기존 데이터를 truncate 하지 않고 caregiver20~27 계정만 idempotent 하게 생성/갱신한다.
 * (재실행해도 이메일/유저 기준 upsert 라 중복 생성 없음.)
 *   php artisan db:seed --class=DemoDomainCaregiverSeeder
 */
class DemoDomainCaregiverSeeder extends Seeder
{
    /** 활동지역 좌표(경기 남부) — DemoSeeder SEOUL_AREAS 와 동일 계열 */
    private const AREAS = [
        ['name' => '화성시 병점동', 'lat' => 37.2070, 'lng' => 127.0330],
        ['name' => '화성시 동탄동', 'lat' => 37.2000, 'lng' => 127.0950],
        ['name' => '오산시 중앙동', 'lat' => 37.1520, 'lng' => 127.0770],
        ['name' => '수원시 영통구', 'lat' => 37.2590, 'lng' => 127.0460],
        ['name' => '용인시 기흥구', 'lat' => 37.2800, 'lng' => 127.1150],
        ['name' => '성남시 분당구', 'lat' => 37.3820, 'lng' => 127.1190],
    ];

    /**
     * 시드 대상 — index 는 caregiver{index}@demo.careand.kr / 전화번호 오프셋에 사용.
     * (0~19 는 DemoSeeder 가 점유하므로 20 부터 시작)
     */
    private const SPECS = [
        // 아이돌봄
        ['idx' => 20, 'name' => '정다은', 'domain' => 'childcare',   'license_type' => '보육교사',   'specialties' => ['아이돌봄', '등하원 동행', '놀이지도']],
        ['idx' => 21, 'name' => '한소미', 'domain' => 'childcare',   'license_type' => '아이돌보미', 'specialties' => ['아이돌봄', '영아 돌봄', '가정보육']],
        ['idx' => 22, 'name' => '배유진', 'domain' => 'childcare',   'license_type' => '유치원정교사', 'specialties' => ['아이돌봄', '학습지도', '등하원 동행']],
        ['idx' => 23, 'name' => '문가영', 'domain' => 'childcare',   'license_type' => '베이비시터', 'specialties' => ['아이돌봄', '영아 돌봄', '놀이지도']],
        // 마음돌봄(상담)
        ['idx' => 24, 'name' => '임서연', 'domain' => 'mental_care', 'license_type' => '상담심리사', 'specialties' => ['마음돌봄', '심리상담', '정서지원']],
        ['idx' => 28, 'name' => '노현주', 'domain' => 'mental_care', 'license_type' => '임상심리사', 'specialties' => ['마음돌봄', '심리상담', '인지행동']],
        ['idx' => 26, 'name' => '백지원', 'domain' => 'mental_care', 'license_type' => '청소년상담사', 'specialties' => ['마음돌봄', '정서지원', '가족상담']],
        ['idx' => 27, 'name' => '강민아', 'domain' => 'mental_care', 'license_type' => '정신건강사회복지사', 'specialties' => ['마음돌봄', '정서지원', '심리상담']],
    ];

    public function run(): void
    {
        foreach (self::SPECS as $n => $spec) {
            $i = $spec['idx'];
            $area = self::AREAS[$n % count(self::AREAS)];

            $user = User::firstOrCreate(
                ['email' => "caregiver{$i}@demo.careand.kr"],
                [
                    'phone' => '010' . str_pad((string) (30000000 + $i * 11), 8, '0', STR_PAD_LEFT),
                    'name' => $spec['name'],
                    'role' => 'caregiver',
                    'password' => 'Demo1234!',  // User 모델 hashed 캐스트가 해싱
                    'phone_verified_at' => now()->subDays(rand(30, 200)),
                    'email_verified_at' => now()->subDays(rand(30, 200)),
                    'status' => 'active',
                ],
            );

            // 기존 계정 보호 — 이미 존재하던 유저(멀티도메인 QA 계정 등)는 절대 덮어쓰지 않는다.
            if (!$user->wasRecentlyCreated && $user->caregiver) {
                $this->command->warn("  skip caregiver{$i}@demo.careand.kr — 기존 계정 보존({$user->name})");
                continue;
            }

            Caregiver::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'birth_date' => now()->subYears(rand(30, 55))->toDateString(),
                    'gender' => 'F',
                    'license_no' => 'DM' . str_pad((string) (200000 + $i), 6, '0', STR_PAD_LEFT),
                    'license_type' => $spec['license_type'],
                    'license_issued_at' => now()->subYears(rand(2, 12))->toDateString(),
                    'license_verified_at' => now()->subDays(rand(30, 300)),
                    'service_domains' => $spec['domain'],  // SET 컬럼 → 콤마 문자열(단일 도메인)
                    'specialties' => $spec['specialties'],
                    'base_address' => '경기 ' . $area['name'] . ' ' . rand(100, 999) . '-' . rand(1, 50),
                    'base_lat' => $area['lat'] + (rand(-300, 300) / 10000),
                    'base_lng' => $area['lng'] + (rand(-300, 300) / 10000),
                    'rating_avg' => round(4.0 + (rand(0, 90) / 100), 2),
                    'rating_count' => rand(8, 60),
                    'completed_sessions' => rand(10, 150),
                    'grade_level' => rand(2, 5),
                    'status' => 'active',
                ],
            );
        }

        $this->command->info('  Domain caregivers seeded: childcare=4, mental_care=4 (caregiver20~27@demo.careand.kr)');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 활성 서비스 카테고리마다 전국 기본 pricing_rules(region_code=null) 1행을 시드한다.
 * 기존 base_rate 기반으로 산출이 즉시 동작하도록 보장(데이터 무손실, 멱등).
 */
return new class extends Migration
{
    public function up(): void
    {
        $minHourly = (float) config('services.pricing.min_hourly', 10030);

        // 신체 친밀/난이도 가산 기본값 — 카테고리 무관 공통(룰별 조정 가능)
        $acuityAddons = json_encode([
            '치매' => 1500,
            '와상' => 2000,
            '석션' => 3000,
            '욕창' => 1500,
            '거동불가' => 1500,
        ], JSON_UNESCAPED_UNICODE);

        $now = now();

        DB::table('service_categories')->where('is_active', true)->orderBy('id')
            ->each(function ($cat) use ($minHourly, $acuityAddons, $now) {
                DB::table('pricing_rules')->updateOrInsert(
                    ['category_id' => $cat->id, 'region_code' => null],
                    [
                        'region_index' => 1.00,
                        'night_mult' => 1.30,
                        'holiday_mult' => 1.50,
                        'emergency_mult' => 1.20,
                        'acuity_addons' => $acuityAddons,
                        'min_hourly' => $minHourly,
                        'is_active' => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            });
    }

    public function down(): void
    {
        DB::table('pricing_rules')->whereNull('region_code')->delete();
    }
};

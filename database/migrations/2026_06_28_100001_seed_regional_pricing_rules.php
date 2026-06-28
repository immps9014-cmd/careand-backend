<?php

use App\Models\PricingRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 활성 카테고리마다 17개 시/도 지역행(pricing_rules.region_code=시도키)을 시드한다.
 * region_index만 시/도별 물가 계수(PricingRule::SIDO_INDEX)로 달리하고, 시간대·긴급 배수와
 * 난이도 가산·최저시급은 해당 카테고리의 전국 기본행(region_code=null)에서 복사한다.
 *
 * 멱등(updateOrInsert). 전국 기본행 부재 시 합성 기본값으로 대체(산출 폴백과 동일 철학).
 * 효과: 주소 첫 토큰을 표준 시/도로 정규화(PricingService::regionCodeOf)해 매칭하므로
 * 서울 1.15 / 경기 1.08 등 지역별 적정가가 반영된다.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $defaultMinHourly = (float) config('services.pricing.min_hourly', 10030);
        $defaultAcuity = json_encode([
            '치매' => 1500,
            '와상' => 2000,
            '석션' => 3000,
            '욕창' => 1500,
            '거동불가' => 1500,
        ], JSON_UNESCAPED_UNICODE);

        $catIds = DB::table('service_categories')->where('is_active', true)
            ->orderBy('id')->pluck('id');

        foreach ($catIds as $cid) {
            // 카테고리별 전국 기본행에서 배수·가산·최저시급을 승계(지역행은 region_index만 다름)
            $nat = DB::table('pricing_rules')
                ->where('category_id', $cid)->whereNull('region_code')->first();

            $base = [
                'night_mult' => $nat->night_mult ?? 1.30,
                'holiday_mult' => $nat->holiday_mult ?? 1.50,
                'emergency_mult' => $nat->emergency_mult ?? 1.20,
                'acuity_addons' => $nat->acuity_addons ?? $defaultAcuity,
                'min_hourly' => $nat->min_hourly ?? $defaultMinHourly,
                'is_active' => true,
            ];

            foreach (PricingRule::SIDO_INDEX as $sido => $index) {
                DB::table('pricing_rules')->updateOrInsert(
                    ['category_id' => $cid, 'region_code' => $sido],
                    array_merge($base, [
                        'region_index' => $index,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ])
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('pricing_rules')
            ->whereIn('region_code', array_keys(PricingRule::SIDO_INDEX))
            ->delete();
    }
};

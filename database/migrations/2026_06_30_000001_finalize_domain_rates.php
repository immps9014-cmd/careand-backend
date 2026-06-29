<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 신규 도메인 카테고리 기준 시급(base_rate) 확정 — Phase 1~4 잠정값 → 최종.
     * 기존 확정 앵커: 방문요양 18,000 · 병원간병 15,000 · 가사청소 20,000 · 야간케어 22,000 · 방문목욕 25,000.
     * (base_rate는 PricingService가 region_index·야간/휴일/긴급 배수와 결합, min_hourly 10,030으로 하한)
     */
    private const FINAL = [
        'LS_COMPANION' => 14000, // 동행 — 경증 동행(시장 수준 하향)
        'PP_CARE'      => 13000, // 산후관리 — 정부 산모신생아 건강관리 바우처 시장 수준으로 하향
        'PP_NIGHT'     => 16000, // 산후 야간케어 — 야간 할증(산후관리 13k 대비 +3k)
        'CC_PICKUP'    => 12000, // 등하원 동행 — 단순 동행(아이돌봄 정부 시간제 수준)
        'CC_PLAY'      => 13000, // 놀이돌봄
        'CC_INFANT'    => 15000, // 영아돌봄 — 영아 고난도(난이도 프리미엄)
        'MC_SUPPORT'   => 15000, // 정서지원 — 말벗·정서지원
        'MC_COMPANION' => 16000, // 심리상담 동행 — 의료기관 동행 포함
        'HK_ORGANIZING' => 22000, // 정리수납(기존) — 청소 대비 전문성 프리미엄 유지하며 하향
        'HK_CLEANING'   => 18000, // 가사 청소(기존) — 하향
        'HK_REPAIR'     => 35000, // 가사 수리(기존) — 숙련 작업, 도메인 내 최고가 유지하며 하향
    ];

    private const PROVISIONAL = [
        'LS_COMPANION' => 16000, 'PP_CARE' => 15000, 'PP_NIGHT' => 18000,
        'CC_PICKUP' => 14000, 'CC_PLAY' => 14000, 'CC_INFANT' => 16000,
        'MC_SUPPORT' => 16000, 'MC_COMPANION' => 16000,
        'HK_ORGANIZING' => 25000, 'HK_CLEANING' => 20000, 'HK_REPAIR' => 40000, // 기존 시드값(롤백 복원용)
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::FINAL as $code => $rate) {
            DB::table('service_categories')->where('code', $code)
                ->update(['base_rate' => $rate, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $now = now();
        foreach (self::PROVISIONAL as $code => $rate) {
            DB::table('service_categories')->where('code', $code)
                ->update(['base_rate' => $rate, 'updated_at' => $now]);
        }
    }
};

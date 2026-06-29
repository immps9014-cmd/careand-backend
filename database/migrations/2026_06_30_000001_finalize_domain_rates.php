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
        'LS_COMPANION' => 16000, // 동행 — 기존 COMPANION 동일(경증 동행)
        'PP_CARE'      => 13000, // 산후관리 — 정부 산모신생아 건강관리 바우처 시장 수준으로 하향
        'PP_NIGHT'     => 18000, // 산후 야간케어 — 야간 할증(산후관리 14k 대비 +4k)
        'CC_PICKUP'    => 13000, // 등하원 동행 — 단순 동행(아이돌봄 정부 시간제 수준)
        'CC_PLAY'      => 14000, // 놀이돌봄
        'CC_INFANT'    => 16000, // 영아돌봄 — 영아 고난도
        'MC_SUPPORT'   => 16000, // 정서지원 — 말벗·정서지원
        'MC_COMPANION' => 17000, // 심리상담 동행 — 의료기관 동행 포함
    ];

    private const PROVISIONAL = [
        'LS_COMPANION' => 16000, 'PP_CARE' => 15000, 'PP_NIGHT' => 18000,
        'CC_PICKUP' => 14000, 'CC_PLAY' => 14000, 'CC_INFANT' => 16000,
        'MC_SUPPORT' => 16000, 'MC_COMPANION' => 16000,
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

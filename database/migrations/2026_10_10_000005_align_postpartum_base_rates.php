<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 산모신생아 일반(바우처 밖) 시간당 기준단가를 바우처 하루 가격에 맞춘다(사용자 결정 2026-10-10, 권장안).
 * 2026 고시 A형 단축 732,000원 ÷ 5일 = 146,400원/일 ÷ 8시간 = 18,300원.
 * 이전: 산모 건강관리·신생아 돌봄 모두 13,000원 — 산모 건강관리는 과거 입찰 시세가 섞여 18,000원으로,
 * 신생아 돌봄은 시세가 없어 13,000원으로 보여 같은 관리사 일인데 30% 차이가 났다. 야간 케어(16,000원×야간 1.3)는 그대로.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('service_categories')->whereIn('code', ['PP_CARE', 'PP_NEWBORN'])->update(['base_rate' => 18300, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('service_categories')->whereIn('code', ['PP_CARE', 'PP_NEWBORN'])->update(['base_rate' => 13000, 'updated_at' => now()]);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 산모·산후관리 → 「산모신생아 건강관리」 명칭 교체 (2026-10-05 사용자 요청).
     * 도메인 토큰(postpartum)·코드(PP_*)는 그대로 두고 표시 이름만 바꾼다.
     */
    private const NAMES = [
        'PP_CARE'  => ['산후관리', '산모 건강관리', '산모·신생아 방문 건강관리(산모 회복·수유·신생아 돌봄 지원)'],
        'PP_NIGHT' => ['산후 야간케어', '야간 케어', '야간 신생아 돌봄·수유 지원'],
    ];

    public function up(): void
    {
        foreach (self::NAMES as $code => [, $name, $desc]) {
            DB::table('service_categories')->where('code', $code)
                ->update(['name' => $name, 'description' => $desc, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('service_categories')->where('code', 'PP_CARE')
            ->update(['name' => '산후관리', 'description' => '산모·신생아 방문 케어(수유·신생아 돌봄·산모 회복 지원)']);
        DB::table('service_categories')->where('code', 'PP_NIGHT')
            ->update(['name' => '산후 야간케어', 'description' => '야간 신생아 돌봄·수유 지원']);
    }
};

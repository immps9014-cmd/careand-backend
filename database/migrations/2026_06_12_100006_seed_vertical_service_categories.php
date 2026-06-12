<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 간병·가사 카테고리를 is_active=0(잠복)으로 투입.
     * 버티컬 오픈은 is_active=1 데이터 토글로 수행(즉시 롤백 가능).
     */
    private const CODES = ['NURSING_HOSPITAL', 'HK_CLEANING', 'HK_REPAIR', 'HK_ORGANIZING'];

    public function up(): void
    {
        $now = now();
        $rows = [
            ['code' => 'NURSING_HOSPITAL', 'name' => '병원 간병', 'base_rate' => 15000],
            ['code' => 'HK_CLEANING', 'name' => '가사 청소', 'base_rate' => 20000],
            ['code' => 'HK_REPAIR', 'name' => '가사 수리', 'base_rate' => 40000],
            ['code' => 'HK_ORGANIZING', 'name' => '정리수납', 'base_rate' => 25000],
        ];

        foreach ($rows as $row) {
            DB::table('service_categories')->insertOrIgnore($row + [
                'is_active' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // 활성화된 적 있는(=운영 사용) 카테고리는 남긴다
        DB::table('service_categories')
            ->whereIn('code', self::CODES)
            ->where('is_active', 0)
            ->delete();
    }
};

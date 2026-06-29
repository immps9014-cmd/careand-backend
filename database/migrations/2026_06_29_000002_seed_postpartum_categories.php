<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 2 — 산모·산후관리(postpartum) 카테고리 + 가격규칙 시드(오픈).
     * 요율은 잠정값 — 정책 확정 시 service_categories.base_rate 갱신.
     * pricing_rules는 카테고리 무관(지역지수·배수·최저시급) → COMPANION(id=2) 템플릿을 복제.
     *
     * 설계서: /root/CAREAND-DOMAIN-INTEGRATION.md (Phase 2)
     */
    private const ROWS = [
        ['code' => 'PP_CARE',  'name' => '산후관리',     'base_rate' => 15000, 'description' => '산모·신생아 방문 케어(수유·신생아 돌봄·산모 회복 지원)'],
        ['code' => 'PP_NIGHT', 'name' => '산후 야간케어', 'base_rate' => 18000, 'description' => '야간 신생아 돌봄·수유 지원'],
    ];

    public function up(): void
    {
        $now = now();
        // 가격규칙 템플릿: COMPANION(id=2)의 전국+지역 규칙 (카테고리 무관 지수/배수)
        $template = DB::table('service_categories')->where('code', 'COMPANION')->value('id');

        foreach (self::ROWS as $r) {
            $id = DB::table('service_categories')->where('code', $r['code'])->value('id');
            if (!$id) {
                $id = DB::table('service_categories')->insertGetId([
                    'code'        => $r['code'],
                    'domain'      => 'postpartum',
                    'name'        => $r['name'],
                    'description' => $r['description'],
                    'base_rate'   => $r['base_rate'],
                    'is_active'   => 1,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }

            // 가격규칙 복제 (멱등)
            if ($template && DB::table('pricing_rules')->where('category_id', $id)->doesntExist()) {
                foreach (DB::table('pricing_rules')->where('category_id', $template)->get() as $pr) {
                    $row = (array) $pr;
                    unset($row['id']);
                    $row['category_id'] = $id;
                    $row['created_at']  = $now;
                    $row['updated_at']  = $now;
                    DB::table('pricing_rules')->insert($row);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::ROWS as $r) {
            $id = DB::table('service_categories')->where('code', $r['code'])->value('id');
            if ($id) {
                DB::table('pricing_rules')->where('category_id', $id)->delete();
                DB::table('service_categories')->where('id', $id)->delete();
            }
        }
    }
};

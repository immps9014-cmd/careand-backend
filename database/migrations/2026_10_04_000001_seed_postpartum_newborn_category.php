<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 산모·산후관리(postpartum)에 「신생아 돌봄」 서비스 종류 추가 (2026-10-04 사용자 요청).
     * 요율은 산후관리(PP_CARE)와 같은 13,000원(사용자 결정).
     * pricing_rules는 카테고리 무관(지역지수·배수·최저시급) → 같은 도메인 PP_CARE 규칙을 복제.
     */
    private const ROW = ['code' => 'PP_NEWBORN', 'name' => '신생아 돌봄', 'base_rate' => 13000, 'description' => '신생아 전담 돌봄(수유·목욕·재우기·기저귀·트림)'];

    public function up(): void
    {
        $now = now();
        $template = DB::table('service_categories')->where('code', 'PP_CARE')->value('id');

        $id = DB::table('service_categories')->where('code', self::ROW['code'])->value('id');
        if (!$id) {
            $id = DB::table('service_categories')->insertGetId([
                'code'        => self::ROW['code'],
                'domain'      => 'postpartum',
                'name'        => self::ROW['name'],
                'description' => self::ROW['description'],
                'base_rate'   => self::ROW['base_rate'],
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

    public function down(): void
    {
        $id = DB::table('service_categories')->where('code', self::ROW['code'])->value('id');
        if ($id) {
            DB::table('pricing_rules')->where('category_id', $id)->delete();
            DB::table('service_categories')->where('id', $id)->delete();
        }
    }
};

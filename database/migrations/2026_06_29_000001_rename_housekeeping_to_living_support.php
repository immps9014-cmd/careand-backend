<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 1 — 가사(housekeeping) → 생활지원서비스(living_support) 토큰 전환 + 동행 편입.
     *
     *  1) enum/SET 4곳에 'living_support' append (말미 추가만 — 정수 인코딩 보존, ALGORITHM=INSTANT).
     *  2) 기존 housekeeping 데이터를 living_support로 이전 (요청 0건·카테고리 3건·캐어기버 SET 6건).
     *  3) 동행: senior COMPANION 비활성 → living_support LS_COMPANION 신설(+ 가격규칙 복제).
     *
     * 설계서: /root/CAREAND-DOMAIN-INTEGRATION.md (4.2 / 4.4)
     * 'housekeeping' enum 값 자체는 잠재 잔존(append-only). 신규 코드는 노출하지 않음.
     */
    public function up(): void
    {
        // ── 1) enum/SET append ───────────────────────────────────────────
        DB::statement("ALTER TABLE match_requests
            MODIFY service_domain ENUM('senior','postpartum','nursing','housekeeping','living_support')
            NOT NULL DEFAULT 'senior' COMMENT '서비스 도메인', ALGORITHM=INSTANT");

        DB::statement("ALTER TABLE caregivers
            MODIFY service_domains SET('senior','postpartum','nursing','housekeeping','living_support')
            NOT NULL DEFAULT 'senior' COMMENT '활동 도메인 SET', ALGORITHM=INSTANT");

        DB::statement("ALTER TABLE mock_interviews
            MODIFY target_domain ENUM('senior','postpartum','nursing','housekeeping','living_support')
            NOT NULL, ALGORITHM=INSTANT");

        DB::statement("ALTER TABLE self_introduction_interviews
            MODIFY extracted_target_domain ENUM('senior','postpartum','both','nursing','housekeeping','living_support')
            NULL DEFAULT NULL, ALGORITHM=INSTANT");

        // ── 2) 데이터 이전 ───────────────────────────────────────────────
        DB::table('match_requests')->where('service_domain', 'housekeeping')
            ->update(['service_domain' => 'living_support']);

        DB::table('service_categories')->where('domain', 'housekeeping')
            ->update(['domain' => 'living_support']);

        DB::statement("UPDATE caregivers
            SET service_domains = TRIM(BOTH ',' FROM
                REPLACE(CONCAT(',', service_domains, ','), ',housekeeping,', ',living_support,'))
            WHERE FIND_IN_SET('housekeeping', service_domains)");

        // ── 3) 동행 편입 ─────────────────────────────────────────────────
        $now = now();
        $companion = DB::table('service_categories')->where('code', 'COMPANION')->first();
        if ($companion) {
            // 시니어 COMPANION 비활성 (과거 데이터 무결성 위해 행은 보존)
            DB::table('service_categories')->where('id', $companion->id)
                ->update(['is_active' => 0, 'updated_at' => $now]);
        }

        $lsId = DB::table('service_categories')->where('code', 'LS_COMPANION')->value('id');
        if (!$lsId) {
            $lsId = DB::table('service_categories')->insertGetId([
                'code'        => 'LS_COMPANION',
                'domain'      => 'living_support',
                'name'        => '동행',
                'description' => '병원·관공서·외출 등 동행 지원',
                'base_rate'   => $companion->base_rate ?? 16000,
                'is_active'   => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // 가격규칙 복제 (COMPANION → LS_COMPANION). 멱등: 이미 있으면 스킵.
        if ($companion && DB::table('pricing_rules')->where('category_id', $lsId)->doesntExist()) {
            $rules = DB::table('pricing_rules')->where('category_id', $companion->id)->get();
            foreach ($rules as $r) {
                $row = (array) $r;
                unset($row['id']);
                $row['category_id'] = $lsId;
                $row['created_at']  = $now;
                $row['updated_at']  = $now;
                DB::table('pricing_rules')->insert($row);
            }
        }
    }

    public function down(): void
    {
        $now = now();

        // 동행 롤백: LS_COMPANION 가격규칙·행 제거, senior COMPANION 재활성
        $lsId = DB::table('service_categories')->where('code', 'LS_COMPANION')->value('id');
        if ($lsId) {
            DB::table('pricing_rules')->where('category_id', $lsId)->delete();
            DB::table('service_categories')->where('id', $lsId)->delete();
        }
        DB::table('service_categories')->where('code', 'COMPANION')
            ->update(['is_active' => 1, 'updated_at' => $now]);

        // 데이터 원복
        DB::table('match_requests')->where('service_domain', 'living_support')
            ->update(['service_domain' => 'housekeeping']);
        DB::table('service_categories')->where('domain', 'living_support')
            ->update(['domain' => 'housekeeping']);
        DB::statement("UPDATE caregivers
            SET service_domains = TRIM(BOTH ',' FROM
                REPLACE(CONCAT(',', service_domains, ','), ',living_support,', ',housekeeping,'))
            WHERE FIND_IN_SET('living_support', service_domains)");

        // enum/SET 값은 append-only 원칙상 축소하지 않음(living_support 잔존 — 무해).
    }
};

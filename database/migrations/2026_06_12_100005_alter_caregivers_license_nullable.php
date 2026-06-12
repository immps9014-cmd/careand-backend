<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 가사(housekeeping) 인력은 요양보호사 자격증이 없을 수 있다.
     * 도메인별 자격 검증은 Phase 2에서 CaregiverController 등록 로직으로 분기.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE caregivers
            MODIFY license_no VARCHAR(30) NULL COMMENT '요양보호사 자격번호(가사 인력은 선택)'");
        DB::statement("ALTER TABLE caregivers MODIFY license_issued_at DATE NULL");
    }

    public function down(): void
    {
        if (DB::table('caregivers')->whereNull('license_no')->exists()) {
            throw new RuntimeException('license_no NULL 인력 존재 — NOT NULL 복귀 불가');
        }

        DB::statement("ALTER TABLE caregivers
            MODIFY license_no VARCHAR(30) NOT NULL COMMENT '요양보호사 자격번호'");
        DB::statement("ALTER TABLE caregivers MODIFY license_issued_at DATE NOT NULL");
    }
};

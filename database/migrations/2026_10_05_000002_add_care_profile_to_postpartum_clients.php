<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 산모신생아 건강관리 신청 정보 구조화 (요구사항분석 PDF 「이용자 회원가입」, 2026-10-05).
     * 조리원·가족구성·반려동물·CCTV·희망사항·희망 제공인력 — 산모(가정) 단위로 한 번 적고 신청마다 재사용.
     * 형식은 App\Support\PostpartumCareProfile 이 SSOT. (MariaDB 10.3 — AFTER 없이 끝에 추가)
     */
    public function up(): void
    {
        if (!Schema::hasColumn('postpartum_clients', 'care_profile')) {
            Schema::table('postpartum_clients', function (Blueprint $t) {
                $t->json('care_profile')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('postpartum_clients', 'care_profile')) {
            Schema::table('postpartum_clients', fn (Blueprint $t) => $t->dropColumn('care_profile'));
        }
    }
};

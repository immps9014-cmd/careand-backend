<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 상담(mental_care) 자격검증 고도화 — caregivers.license_type 추가.
 *
 * 기존 스키마는 단일 license_no/license_image_url/license_issued_at/license_verified_at 만 보유.
 * 상담 도메인은 자격이 다종(상담심리사·임상심리사·정신건강전문요원·사회복지사 등)이라
 * 어떤 자격으로 등록했는지(종류)를 별도로 식별해야 관리자 수동 검증이 가능하다.
 * → license_type(자격증 종류) 컬럼을 append (INSTANT, 기존 행은 NULL).
 *
 * @see config/service_domains.php  qualification.accepted_types
 * @see /root/CAREAND-DOMAIN-INTEGRATION.md  남은 과제: 상담 자격 검증 고도화
 */
return new class extends Migration
{
    public function up(): void
    {
        // license_no 바로 뒤에 INSTANT append (잠금 없음)
        DB::statement("ALTER TABLE caregivers ADD COLUMN license_type VARCHAR(40) NULL AFTER license_no, ALGORITHM=INSTANT");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE caregivers DROP COLUMN license_type");
    }
};

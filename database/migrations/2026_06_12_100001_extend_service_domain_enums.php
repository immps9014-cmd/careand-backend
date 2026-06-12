<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 간병(nursing)·가사(housekeeping) 버티컬 — 도메인 enum/SET 확장.
     * 기존 값의 순서 변경·삭제 금지(내부 정수 인코딩 손상). 말미 append만 허용.
     * ALGORITHM=INSTANT 명시: 메타데이터 변경이 아니면 즉시 에러로 드러나게 한다.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE match_requests
            MODIFY service_domain ENUM('senior','postpartum','nursing','housekeeping')
            NOT NULL DEFAULT 'senior' COMMENT '서비스 도메인', ALGORITHM=INSTANT");

        DB::statement("ALTER TABLE caregivers
            MODIFY service_domains SET('senior','postpartum','nursing','housekeeping')
            NOT NULL DEFAULT 'senior' COMMENT '활동 도메인 SET', ALGORITHM=INSTANT");

        DB::statement("ALTER TABLE care_activities
            MODIFY category ENUM('meal','medication','exercise','bath','mood','cognition','other',
                'cleaning','repair','organizing','nursing_care','position_change')
            NOT NULL, ALGORITHM=INSTANT");

        // 비시니어 도메인 요청은 senior_id가 없다 (소형 테이블, 리빌드 즉시 완료)
        DB::statement("ALTER TABLE match_requests MODIFY senior_id BIGINT(20) UNSIGNED NULL");
    }

    public function down(): void
    {
        // 신규 값을 쓰는 행이 있으면 enum 축소가 데이터를 파괴하므로 차단
        $inUse = DB::table('match_requests')->whereIn('service_domain', ['nursing', 'housekeeping'])->exists()
            || DB::table('match_requests')->whereNull('senior_id')->exists()
            || DB::table('care_activities')->whereIn('category', ['cleaning', 'repair', 'organizing', 'nursing_care', 'position_change'])->exists()
            || DB::table('caregivers')->where(function ($q) {
                $q->whereRaw("FIND_IN_SET('nursing', service_domains)")
                    ->orWhereRaw("FIND_IN_SET('housekeeping', service_domains)");
            })->exists();

        if ($inUse) {
            throw new RuntimeException('신규 도메인 값이 사용 중 — enum 축소 롤백 불가');
        }

        DB::statement("ALTER TABLE match_requests MODIFY senior_id BIGINT(20) UNSIGNED NOT NULL");
        DB::statement("ALTER TABLE care_activities
            MODIFY category ENUM('meal','medication','exercise','bath','mood','cognition','other') NOT NULL");
        DB::statement("ALTER TABLE caregivers
            MODIFY service_domains SET('senior','postpartum')
            NOT NULL DEFAULT 'senior' COMMENT '활동 도메인 SET'");
        DB::statement("ALTER TABLE match_requests
            MODIFY service_domain ENUM('senior','postpartum')
            NOT NULL DEFAULT 'senior' COMMENT '서비스 도메인'");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 5 — 교육 도메인 enum 확장 (간병·가사 버티컬).
     * 기존 값의 순서 변경·삭제 금지(내부 정수 인코딩 손상). 말미 append만 허용.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE mock_interviews
            MODIFY target_domain ENUM(\x27senior\x27,\x27postpartum\x27,\x27nursing\x27,\x27housekeeping\x27)
            NOT NULL, ALGORITHM=INSTANT");

        DB::statement("ALTER TABLE self_introduction_interviews
            MODIFY extracted_target_domain ENUM(\x27senior\x27,\x27postpartum\x27,\x27both\x27,\x27nursing\x27,\x27housekeeping\x27)
            NULL DEFAULT NULL, ALGORITHM=INSTANT");
    }

    public function down(): void
    {
        // enum 축소는 데이터 손상 위험 — 신규 값 사용 행이 없을 때만 수동 복원
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 인력 가입 보강(2026-10-05, 요구사항분석 PDF 「인력 회원가입」 비상연락처·사진·특이/희망사항).
 * - emergency_contact: {name, relation, phone} JSON 을 MedicalCrypto 로 암호화한 문자열(제3자 개인정보).
 * - work_preferences: {days[1..7], times[day|evening|night|live_in], regions, note} — 매칭 참고, 이용자 비공개.
 * - photo_path: storage/app/caregiver-photos/{id}.jpg (600px 안으로 줄인 JPEG). 서명 링크로만 내보낸다.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('caregivers', function (Blueprint $table) {
            $table->text('emergency_contact')->nullable()->comment('암호화 JSON {name, relation, phone}');
            $table->json('work_preferences')->nullable()->comment('희망 요일·시간대·지역·특이사항');
            $table->string('photo_path', 200)->nullable();
            $table->timestamp('photo_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('caregivers', function (Blueprint $table) {
            $table->dropColumn(['emergency_contact', 'work_preferences', 'photo_path', 'photo_updated_at']);
        });
    }
};

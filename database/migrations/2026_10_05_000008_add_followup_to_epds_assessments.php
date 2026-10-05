<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 관리자 산후우울 검사 결과 화면(2026-10-05) — 고위험 검사에 대한 운영팀 후속 조치 기록.
 * followup_status: contacted(연락함) | linked(마음돌봄·상담 연결) | referred(의료기관 안내) | closed(추가 조치 없음)
 * 비어 있으면 「조치 전」. action_taken(시스템 자동 권고)과는 별개다.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('epds_assessments', function (Blueprint $table) {
            $table->string('followup_status', 20)->nullable()->comment('contacted|linked|referred|closed');
            $table->text('followup_note')->nullable();
            $table->timestamp('followed_up_at')->nullable();
            $table->unsignedBigInteger('followed_up_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('epds_assessments', function (Blueprint $table) {
            $table->dropColumn(['followup_status', 'followup_note', 'followed_up_at', 'followed_up_by']);
        });
    }
};

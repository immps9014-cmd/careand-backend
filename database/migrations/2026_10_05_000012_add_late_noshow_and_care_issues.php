<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 지각·노쇼 감지와 보호자의 교체 요청·신고(2026-10-05, CAREN-TODO-01 「회원 화면」).
 * - care_sessions.late_alerted_at / noshow_alerted_at: matching:watch 가 한 번만 알리도록 표시(상태는 자동으로 바꾸지 않는다).
 * - care_issue_reports: 보호자가 진행 중·끝난 매칭에 남기는 교체 요청(replace)·신고(report). 운영팀(CS)이 처리 상태·답변을 남긴다.
 *   이벤트 시각 칸은 nullable — 첫 timestamp 칸 ON UPDATE 자동 부착 함정(마이그 000011) 회피.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('care_sessions', function (Blueprint $table) {
            $table->timestamp('late_alerted_at')->nullable();
            $table->timestamp('noshow_alerted_at')->nullable();
        });

        Schema::create('care_issue_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('match_id');
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('caregiver_id');
            $table->unsignedBigInteger('reporter_user_id');
            $table->unsignedBigInteger('care_session_id')->nullable();
            $table->string('kind', 10)->comment('replace|report');
            $table->string('category', 20)->comment('late|no_show|attitude|skill|safety|hygiene|privacy|other');
            $table->text('detail');
            $table->string('status', 15)->default('open')->comment('open|in_progress|resolved|rejected');
            $table->text('admin_reply')->nullable();
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('match_id');
            $table->index('caregiver_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_issue_reports');
        Schema::table('care_sessions', function (Blueprint $table) {
            $table->dropColumn(['late_alerted_at', 'noshow_alerted_at']);
        });
    }
};

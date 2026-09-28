<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 사업계획서 KPI 3 「업무처리 리드타임(케어일지 작성시간)」 측정용 시각 (2026-09-28, 구현계획 S1).
 *   log_started_at : 케어 종료 후 일지 작성을 시작한 시각
 *                    (케어 중 이미 기록이 있으면 퇴근 시각, 없으면 퇴근 후 첫 기록 시각)
 *   log_sent_at    : 보호자에게 일지가 전송된 시각 (보호자가 볼 수 있게 된 최초 승인 시각)
 * KPI = AVG(log_sent_at - log_started_at). 기존 세션은 근거가 없어 채우지 않는다.
 * STT 케어용어 인식률(KPI 1) 평가 결과는 stt_evaluations 에 쌓는다.
 * MariaDB 10.3 — AFTER 없이 끝에 추가(INSTANT 조합 회피).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_sessions', function (Blueprint $table) {
            $table->timestamp('log_started_at')->nullable()->comment('KPI: 일지 작성 시작');
            $table->timestamp('log_sent_at')->nullable()->comment('KPI: 보호자 전송 완료');
        });

        Schema::create('stt_evaluations', function (Blueprint $table) {
            $table->id();
            $table->string('run_label', 100)->comment('평가 실행 이름');
            $table->string('term_set', 50)->comment('CareTerm 기준 목록 이름');
            $table->string('stt_engine', 100)->comment('평가한 STT 엔진·모델');
            $table->unsignedInteger('samples')->comment('평가 음성 수');
            $table->unsignedInteger('terms_total')->comment('정답 전사에 등장한 케어용어 수');
            $table->unsignedInteger('terms_correct')->comment('STT가 맞게 전사한 케어용어 수');
            $table->decimal('rate', 5, 2)->comment('정상 인식률(%)');
            $table->json('details')->nullable()->comment('용어별·음성별 결과');
            $table->timestamp('evaluated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stt_evaluations');
        Schema::table('care_sessions', function (Blueprint $table) {
            $table->dropColumn(['log_started_at', 'log_sent_at']);
        });
    }
};

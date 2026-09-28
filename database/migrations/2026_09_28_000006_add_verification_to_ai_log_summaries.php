<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI 일지 사실성 검증 결과 — 사업계획서 기능 29·22·41 (2026-09-28, 구현계획 S3).
 *   risk_score   : 원문에 근거 없는 주장 비율(0~1, AI 서비스 verify.py)
 *   verification : 근거 없는 주장·민감정보·안전 알림·검수 사유 JSON
 * 검수 여부는 CareLogReviewService 가 이 값으로 정한다(위험 없으면 자동 승인 → 보호자 즉시 전송).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_log_summaries', function (Blueprint $table) {
            $table->decimal('risk_score', 4, 3)->nullable()->comment('사실성 위험도 0~1');
            $table->json('verification')->nullable()->comment('사실성 검증·안전 알림 결과');
        });
    }

    public function down(): void
    {
        Schema::table('ai_log_summaries', function (Blueprint $table) {
            $table->dropColumn(['risk_score', 'verification']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 월간 결산 — 사업계획서 기능 23·26 「월간 결산 리포트 자동 생성」(2026-09-28, 구현계획 S5).
 * reports:monthly-close 가 매월 1일 새벽(KST) 전월분을 만든다. 같은 달을 다시 돌리면 덮어쓴다(수정 이력은 generated_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_reports', function (Blueprint $table) {
            $table->id();
            $table->char('month', 7)->unique()->comment('YYYY-MM (한국 시각 기준 달)');
            $table->json('data')->comment('매출·결제·정산·매칭·돌봄·회원·후기·KPI 집계');
            $table->timestamp('generated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_reports');
    }
};

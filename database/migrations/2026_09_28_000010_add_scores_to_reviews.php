<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 후기 도메인별 항목 점수 + 낮은 평점 알림 시각 — 기능 7·24(2026-09-28, 구현계획 S5).
 * MariaDB 10.3: AFTER 없이 끝에 추가.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->json('scores')->nullable()->comment('도메인별 평가 항목 점수 {항목키: 1~5} — config/review_criteria.php');
            $table->timestamp('flagged_at')->nullable()->comment('2점 이하 → 운영팀 알림 보낸 시각(답변 SLA 기준점)');
        });
        // 기존 2점 이하 후기도 SLA 추적 대상으로
        DB::table('reviews')->where('rating', '<=', 2)->whereNull('flagged_at')->update(['flagged_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['scores', 'flagged_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 매칭 시간 규칙용 시각 컬럼 — 기능 10·11·18(2026-09-28, 구현계획 S5). MariaDB 10.3: AFTER 없이.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_candidates', function (Blueprint $table) {
            $table->timestamp('offered_at')->nullable()->comment('보호자가 이 후보를 지정한 시각');
            $table->timestamp('offer_expires_at')->nullable()->comment('응답 마감 — 지나면 matching:watch 가 자동 거절');
            $table->index(['response', 'offer_expires_at']);
        });
        Schema::table('match_requests', function (Blueprint $table) {
            $table->timestamp('unmatched_alerted_at')->nullable()->comment('장시간 미매칭 관리자 알림 보낸 시각');
        });
        Schema::table('care_sessions', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->comment('방문 전 리마인더 보낸 시각');
        });
    }

    public function down(): void
    {
        Schema::table('match_candidates', function (Blueprint $table) {
            $table->dropIndex(['response', 'offer_expires_at']);
            $table->dropColumn(['offered_at', 'offer_expires_at']);
        });
        Schema::table('match_requests', fn (Blueprint $t) => $t->dropColumn('unmatched_alerted_at'));
        Schema::table('care_sessions', fn (Blueprint $t) => $t->dropColumn('reminder_sent_at'));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 출퇴근 반경 밖 기록 — 기능 12 「반경 밖 체크는 운영팀 경고」(2026-09-29, 구현계획 S5 잔여).
 * 반경 밖이지만 허용 한도 안이면 받아 주고 out_of_range=1 로 남겨 운영팀이 확인한다. MariaDB 10.3: AFTER 없이.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->boolean('out_of_range')->default(false)->comment('서비스 장소 반경 밖(허용 한도 안) — 운영팀 경고 대상');
            $table->timestamp('reviewed_at')->nullable()->comment('운영팀이 반경 밖 기록을 확인한 시각');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropColumn(['out_of_range', 'reviewed_at']);
        });
    }
};

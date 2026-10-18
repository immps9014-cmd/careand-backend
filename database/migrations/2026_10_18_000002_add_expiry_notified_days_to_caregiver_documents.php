<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 서류 만료 알림(CAREN-MNH-02 E, 2026-10-18) — 이 서류에 마지막으로 보낸 알림 단계(만료까지 남은 일수: 30·7, 만료 날 0).
 * 같은 단계를 두 번 보내지 않고, 하루 크론이 빠져도 다음 날 밀린 단계를 보낸다. 새 서류를 올리면 새 행이라 다시 null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caregiver_documents', function (Blueprint $t) {
            $t->unsignedSmallInteger('expiry_notified_days')->nullable()->comment('마지막 만료 알림 단계(남은 일수 30|7|0)');
        });
    }

    public function down(): void
    {
        Schema::table('caregiver_documents', function (Blueprint $t) {
            $t->dropColumn('expiry_notified_days');
        });
    }
};

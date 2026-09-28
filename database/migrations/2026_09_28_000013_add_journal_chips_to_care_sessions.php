<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 칩 기반 케어일지 — 기능 40(2026-09-28, 구현계획 S5). 칩 코드는 온톨로지 cj:code(care_journal.json id).
 * 메모는 건강 정보가 들어갈 수 있어 MedicalCrypto 로 암호화 저장. MariaDB 10.3: AFTER 없이.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_sessions', function (Blueprint $table) {
            $table->json('journal_chips')->nullable()->comment('돌봄전문가가 누른 칩 코드 목록');
            $table->text('journal_note')->nullable()->comment('칩과 함께 적은 메모 — MedicalCrypto 암호화');
            $table->timestamp('chips_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('care_sessions', function (Blueprint $table) {
            $table->dropColumn(['journal_chips', 'journal_note', 'chips_updated_at']);
        });
    }
};

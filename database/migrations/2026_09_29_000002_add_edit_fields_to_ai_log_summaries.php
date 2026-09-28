<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 일지 본문 수정 이력 — 기능 14(돌봄전문가 검토·수정)·22(운영자 본문 수정)(2026-09-29, S5 잔여).
 * 처음 수정할 때 AI 원본을 보관하고, 마지막 수정자·역할·사유·시각을 남긴다. MariaDB 10.3: AFTER 없이.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_log_summaries', function (Blueprint $table) {
            $table->longText('guardian_original')->nullable()->comment('AI 가 만든 보호자용 원본(첫 수정 때 보관)');
            $table->longText('medical_original')->nullable()->comment('AI 가 만든 의료진용 원본(첫 수정 때 보관)');
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('edited_role', 12)->nullable()->comment('caregiver|admin');
            $table->string('edit_reason', 255)->nullable();
            $table->timestamp('edited_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_log_summaries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('edited_by');
            $table->dropColumn(['guardian_original', 'medical_original', 'edited_role', 'edit_reason', 'edited_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 케어일지 가족 공유 링크 — 기능 5 「알림톡 링크로 가족 공유」(2026-09-29, S5 잔여).
 * 토큰 원문은 저장하지 않고 SHA-256 만 둔다(DB 가 새도 링크를 만들 수 없게). 7일 만료·언제든 해제.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_log_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('care_sessions')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_log_shares');
    }
};

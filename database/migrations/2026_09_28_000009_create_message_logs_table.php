<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 알림톡·SMS 발송 기록 — 사업계획서 기능 6·32 「카카오 알림톡(실패 시 SMS 대체)」(2026-09-28, 구현계획 S4).
 * 수신 번호는 가운데를 가려 저장한다(발송 증빙·장애 추적용이지 연락처 보관용이 아님).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('notification_id')->nullable()->index();
            $table->string('template', 40)->comment('CAREN_* 템플릿 키 또는 SMS_OTP 등');
            $table->string('channel', 10)->comment('alimtalk|sms|lms');
            $table->string('status', 10)->comment('sent|failed|stub|skipped');
            $table->string('phone_masked', 20)->nullable();
            $table->string('result_code', 20)->nullable();
            $table->string('result_message', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['template', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_logs');
    }
};

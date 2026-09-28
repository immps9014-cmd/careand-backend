<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 회원 블랙리스트 — 기능 19 「블랙리스트 별도 관리」(2026-09-29, S5 잔여).
 * 등록하면 계정 정지 + 같은 휴대폰 번호 재가입 차단. 번호는 원문 대신 HMAC(APP_KEY) 로만 보관.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_blacklist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('phone_hash', 64)->index()->comment('HMAC-SHA256(숫자만 휴대폰, APP_KEY)');
            $table->string('role', 20)->nullable();
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('release_reason', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_blacklist');
    }
};

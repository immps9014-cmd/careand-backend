<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 관리자 2단계 인증(TOTP) — 사업계획서 3.3 「관리자 계정 이중인증(OTP) 필수」(2026-09-28, 구현계획 S2).
 *   totp_secret     : 인증 앱 비밀키(암호화 저장, encrypted cast)
 *   totp_enabled_at : 등록 완료 시각 — NULL 이면 다음 로그인 때 등록을 강제한다
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('totp_secret')->nullable()->comment('2단계 인증 비밀키(암호화)');
            $table->timestamp('totp_enabled_at')->nullable()->comment('2단계 인증 등록 시각');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['totp_secret', 'totp_enabled_at']);
        });
    }
};

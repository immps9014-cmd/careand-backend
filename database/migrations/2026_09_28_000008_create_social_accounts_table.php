<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 소셜 로그인 연결 — 사업계획서 기능 1·33 「카카오·구글 OAuth 2.0 로그인」(2026-09-28, 구현계획 S4).
 * 한 회원에 제공자별 계정 하나. provider_user_id 는 제공자가 주는 고유 번호(이메일은 바뀔 수 있어 식별자로 쓰지 않음).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->comment('kakao|google');
            $table->string('provider_user_id', 100);
            $table->string('email', 190)->nullable()->comment('제공자가 준 이메일(참고용)');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};

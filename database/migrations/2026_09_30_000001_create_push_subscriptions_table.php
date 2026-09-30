<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 웹 푸시 구독 — PWA 1단계(CAREN-PWA-01, 2026-09-30).
 * 브라우저·기기마다 한 행(한 사용자가 폰·PC 여러 개). endpoint 는 길어서(최대 ~500자) 해시로 유일성을 잡는다.
 * 푸시 서비스가 404/410 을 주면 발송 쪽에서 행을 지운다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique()->comment('sha256(endpoint)');
            $table->string('p256dh', 120)->comment('브라우저 공개키(base64url, 65바이트)');
            $table->string('auth', 40)->comment('인증 비밀(base64url, 16바이트)');
            $table->string('user_agent', 255)->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};

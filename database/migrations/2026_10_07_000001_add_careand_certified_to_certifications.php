<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 케어앤에듀 인증 돌봄전문가(2026-10-07). 비어 있던 교육 모듈의 certifications 표를 그대로 쓰고,
 * 케어앤에듀가 직접 주는 자격을 가르는 program 과 취소 기록 칸만 더한다.
 * 시각 칸은 nullable — 첫 timestamp 칸 ON UPDATE 자동 부착 함정(마이그 000011) 회피, 끝에 붙임(10.3 AFTER+INSTANT 미지원).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('certifications', function (Blueprint $table) {
            $table->string('program', 40)->nullable()->comment('careand_certified = 케어앤에듀 인증 돌봄전문가');
            $table->string('grant_basis', 10)->nullable()->comment('auto|manual');
            $table->json('grant_stats')->nullable()->comment('부여 당시 활동 횟수·평점·후기 수');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->string('revoked_reason', 255)->nullable();
            $table->index(['program', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('certifications', function (Blueprint $table) {
            $table->dropIndex(['program', 'user_id']);
            $table->dropColumn(['program', 'grant_basis', 'grant_stats', 'revoked_at', 'revoked_by', 'revoked_reason']);
        });
    }
};

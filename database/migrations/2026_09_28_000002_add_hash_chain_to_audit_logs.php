<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 개인정보 접근 감사로그 — 사업계획서 3.3 접근통제(WHO·WHEN·WHAT·WHERE·WHY + 무결성) (2026-09-28, 구현계획 S2).
 *   reason    : 접근 사유(WHY) — 요청 헤더 X-Access-Reason
 *   prev_hash : 직전 행의 hash
 *   hash      : SHA-256(prev_hash + 이 행 내용) — 행을 고치거나 지우면 이후 체인이 깨진다(php artisan audit:verify)
 * MariaDB 10.3 — AFTER 없이 끝에 추가.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('reason', 300)->nullable()->comment('접근 사유(WHY)');
            $table->char('prev_hash', 64)->nullable()->comment('직전 행 해시');
            $table->char('hash', 64)->nullable()->comment('해시 체인');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['reason', 'prev_hash', 'hash']);
        });
    }
};

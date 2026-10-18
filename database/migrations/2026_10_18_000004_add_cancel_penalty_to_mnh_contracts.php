<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 계약 해지 위약금(CAREN-MNH-02 D, 2026-10-18) — 이용계약서 「해지 {{cancel_notice_days}}일 전에 알리지 않으면 위약금」 조항.
 * 해지를 알린 날과 받은 위약금을 남긴다. 비율은 config mnh_docs.terms.cancel_penalty_pct(제공기관이 정함, 기본 비움).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mnh_contracts', function (Blueprint $t) {
            $t->date('cancel_notice_date')->nullable()->after('cancel_reason')->comment('이용자가 해지를 알린 날(KST)');
            $t->unsignedInteger('penalty_amount')->nullable()->after('cancel_notice_date')->comment('해지 위약금(원) — 미통지 해지일 때');
        });
    }

    public function down(): void
    {
        Schema::table('mnh_contracts', function (Blueprint $t) {
            $t->dropColumn(['cancel_notice_date', 'penalty_amount']);
        });
    }
};

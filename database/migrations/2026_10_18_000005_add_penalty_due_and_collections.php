<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 위약금 미수 차액(CAREN-MNH-02 D 보강, 2026-10-18) — 위약금은 서비스 총 요금 기준이라 선납 잔액(선납 − 받은 서비스분 − 환불)보다
 * 클 수 있다. 그 차액을 계약에 남기고(penalty_due), 나중에 받은 돈은 받은 날마다 한 줄씩 쌓는다(나눠 받을 수 있고, 받은 날 매출).
 * 시각 칸은 datetime — 이 DB 는 표의 첫 timestamp 칸에 ON UPDATE CURRENT_TIMESTAMP 를 저절로 붙인다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mnh_contracts', function (Blueprint $t) {
            $t->unsignedInteger('penalty_due')->nullable()->after('penalty_amount')->comment('위약금 중 선납에서 못 채워 따로 받아야 할 차액(원)');
        });
        Schema::create('mnh_penalty_collections', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('contract_id')->index();
            $t->unsignedInteger('amount')->comment('받은 금액(원)');
            $t->string('method', 20)->comment('cash|card|local_currency|transfer');
            $t->date('collected_on')->comment('받은 날(KST)');
            $t->dateTime('collected_at')->nullable()->comment('매출 집계 시각(UTC) — 받은 날이 오늘이면 기록 시각, 지난날이면 그날 KST 정오');
            $t->string('note', 200)->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->foreign('contract_id')->references('id')->on('mnh_contracts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mnh_penalty_collections');
        Schema::table('mnh_contracts', function (Blueprint $t) {
            $t->dropColumn('penalty_due');
        });
    }
};

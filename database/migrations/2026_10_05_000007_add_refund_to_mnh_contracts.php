<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 바우처 선납 매출 집계(2026-10-05) — 계약 취소 때 돌려준 본인부담금을 기록해 매출에서 뺀다.
 * 선납액은 prepaid_at 시점 매출(+), 환불액은 refunded_at 시점 매출(−). App\Support\VoucherRevenue 참고.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('mnh_contracts', function (Blueprint $table) {
            $table->unsignedInteger('refund_amount')->nullable()->comment('취소 시 돌려준 본인부담금(원)');
            $table->timestamp('refunded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mnh_contracts', function (Blueprint $table) {
            $table->dropColumn(['refund_amount', 'refunded_at']);
        });
    }
};

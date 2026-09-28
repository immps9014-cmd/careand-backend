<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 토스페이먼츠 주문번호 — 결제창 요청·승인(confirm)을 잇는 키 (2026-09-28, 구현계획 S4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('pg_order_id', 64)->nullable()->unique()->comment('PG 주문번호(토스 orderId)');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['pg_order_id']);
            $table->dropColumn('pg_order_id');
        });
    }
};

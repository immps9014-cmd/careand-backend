<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guardian_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_id')->constrained()->cascadeOnDelete();
            $table->decimal('total_amount', 10, 2);
            $table->decimal('amount_self_pay', 10, 2)->comment('자비 (본인부담금)');
            $table->decimal('amount_ltc_pay', 10, 2)->comment('장기요양 청구분');
            $table->string('method', 30)->comment('card, account, voucher_only');
            $table->string('pg_provider', 30)->nullable();
            $table->string('pg_tid', 100)->nullable()->unique();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->enum('status', ['pending', 'paid', 'failed', 'cancelled', 'refunded'])->default('pending');
            $table->json('pg_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('paid_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

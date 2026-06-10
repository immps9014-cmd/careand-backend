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
        Schema::create('ltc_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('senior_id')->constrained()->cascadeOnDelete();
            $table->date('period_month')->comment('YYYY-MM-01 형식');
            $table->decimal('monthly_limit', 10, 2)->comment('월 한도');
            $table->decimal('used_amount', 10, 2)->default(0);
            $table->decimal('remaining_amount', 10, 2);
            $table->tinyInteger('copay_rate')->comment('본인부담률 % (15, 9, 6 등)');
            $table->timestamps();

            $table->unique(['senior_id', 'period_month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ltc_vouchers');
    }
};

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
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caregiver_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('gross_amount', 12, 2)->comment('세전 정산액');
            $table->decimal('withholding_tax_3_3', 12, 2)->comment('3.3% 원천징수');
            $table->decimal('net_amount', 12, 2)->comment('실지급액');
            $table->string('hometax_filing_no', 50)->nullable();
            $table->string('bank_tx_id', 100)->nullable();
            $table->enum('status', ['draft', 'confirmed', 'paid', 'failed'])->default('draft');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['caregiver_id', 'period_start']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};

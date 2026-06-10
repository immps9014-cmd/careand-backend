<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * voucher_transactions: 사회서비스 전자바우처(SBA) 거래
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('voucher_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('postpartum_client_id');
            $table->enum('voucher_type', ['mother_newborn_care'])->default('mother_newborn_care');
            $table->enum('transaction_type', ['issue', 'use', 'refund', 'expire']);
            $table->decimal('amount', 12, 2);
            $table->integer('days')->nullable();
            $table->unsignedBigInteger('care_session_id')->nullable();
            $table->date('transaction_date');
            $table->string('sba_transaction_id', 100)->nullable()->comment('SBA 거래번호');
            $table->json('sba_response')->nullable();
            $table->enum('status', ['pending', 'success', 'failed', 'cancelled'])->default('pending');
            $table->string('failed_reason', 500)->nullable();
            $table->timestamps();

            $table->index('postpartum_client_id');
            $table->index('care_session_id');
            $table->index('status');
            $table->index('sba_transaction_id');

            $table->foreign('postpartum_client_id')
                ->references('id')->on('postpartum_clients')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_transactions');
    }
};

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
        Schema::create('settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('care_sessions')->cascadeOnDelete();
            $table->decimal('hours', 5, 2);
            $table->decimal('hourly_rate', 10, 2);
            $table->decimal('amount', 10, 2);
            $table->decimal('surcharge', 10, 2)->default(0)->comment('할증');
            $table->timestamps();

            $table->index('settlement_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settlement_items');
    }
};

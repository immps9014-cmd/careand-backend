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
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('match_requests')->cascadeOnDelete();
            $table->foreignId('caregiver_id')->constrained()->cascadeOnDelete();
            $table->timestamp('scheduled_start');
            $table->timestamp('scheduled_end');
            $table->decimal('hourly_rate', 10, 2);
            $table->decimal('estimated_amount', 10, 2);
            $table->enum('status', ['confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'])->default('confirmed');
            $table->timestamps();

            $table->index('status');
            $table->index('scheduled_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};

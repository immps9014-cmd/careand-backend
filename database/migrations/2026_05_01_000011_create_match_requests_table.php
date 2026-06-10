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
        Schema::create('match_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guardian_id')->constrained()->cascadeOnDelete();
            $table->foreignId('senior_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('service_categories');
            $table->enum('mode', ['normal', 'emergency', 'recurring']);
            $table->timestamp('scheduled_start');
            $table->unsignedInteger('duration_min')->comment('소요 시간(분)');
            $table->json('recurrence_rule')->nullable()->comment('RRULE (정기 매칭)');
            $table->text('special_request')->nullable();
            $table->enum('status', ['open', 'matching', 'matched', 'expired', 'cancelled'])->default('open');
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_start']);
            $table->index('mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match_requests');
    }
};

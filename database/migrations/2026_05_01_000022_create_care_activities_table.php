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
        Schema::create('care_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('care_sessions')->cascadeOnDelete();
            $table->enum('category', ['meal', 'medication', 'exercise', 'bath', 'mood', 'cognition', 'other']);
            $table->json('data')->comment('카테고리별 데이터: meal_pct, medication_taken 등');
            $table->text('memo')->nullable();
            $table->timestamp('performed_at');
            $table->timestamps();

            $table->index(['session_id', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('care_activities');
    }
};

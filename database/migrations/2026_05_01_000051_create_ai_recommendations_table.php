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
        Schema::create('ai_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('senior_id')->constrained()->cascadeOnDelete();
            $table->foreignId('model_id')->constrained('ai_models');
            $table->enum('recommendation_type', ['matching', 'health_intervention', 'content', 'schedule']);
            $table->json('input_summary');
            $table->json('result');
            $table->decimal('confidence', 4, 3);
            $table->boolean('was_adopted')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['senior_id', 'recommendation_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_recommendations');
    }
};

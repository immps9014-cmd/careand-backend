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
        Schema::create('ai_inference_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_id')->constrained('ai_models');
            $table->json('input_summary')->nullable();
            $table->decimal('latency_ms', 10, 2);
            $table->boolean('success')->default(true);
            $table->text('error')->nullable();
            $table->timestamp('inferred_at');
            $table->timestamps();

            $table->index(['model_id', 'inferred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_inference_logs');
    }
};

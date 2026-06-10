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
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->string('model_name', 50)->comment('matching, stt, llm, anomaly, forecast');
            $table->string('version', 30);
            $table->enum('status', ['active', 'shadow', 'deprecated'])->default('shadow');
            $table->decimal('accuracy', 5, 4)->nullable();
            $table->decimal('avg_latency_ms', 10, 2)->nullable();
            $table->json('metadata')->nullable()->comment('endpoint, params 등');
            $table->timestamp('audited_at')->nullable();
            $table->json('bias_report')->nullable()->comment('편향성 감사 결과');
            $table->timestamps();

            $table->unique(['model_name', 'version']);
            $table->index(['model_name', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};

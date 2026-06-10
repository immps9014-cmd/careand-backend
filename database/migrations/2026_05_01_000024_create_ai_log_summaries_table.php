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
        Schema::create('ai_log_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('care_sessions')->cascadeOnDelete();
            $table->foreignId('voice_log_id')->nullable()->constrained('voice_logs')->nullOnDelete();
            $table->text('guardian_version')->comment('보호자용 친근한 톤');
            $table->text('medical_version')->comment('의료진용 정형 차트');
            $table->json('categorized')->comment('식사/복약/운동/정서 등 분류');
            $table->decimal('confidence', 4, 3);
            $table->string('llm_model', 50)->comment('claude-opus-4.7 등');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index('session_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_log_summaries');
    }
};

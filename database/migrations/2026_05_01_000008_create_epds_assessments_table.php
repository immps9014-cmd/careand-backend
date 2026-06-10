<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EPDS (Edinburgh Postnatal Depression Scale)
 * 10문항 0~3점 (총 0~30점)
 * 13점 이상 또는 q10 1점 이상 → 즉시 알림
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('epds_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('postpartum_client_id');
            $table->date('assessment_date');

            // 10문항 0~3점
            for ($i = 1; $i <= 10; $i++) {
                $comment = $i === 10 ? '자해 사고 — 1점 이상이면 즉시 알림' : null;
                $table->integer("q{$i}_score")->comment($comment ?? "Q{$i} 점수 (0-3)");
            }

            $table->integer('total_score')->comment('총점 (0~30)');
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical']);

            // LLM 결합
            $table->decimal('llm_sentiment_score', 5, 4)->nullable();
            $table->decimal('combined_risk_score', 5, 4)->nullable();

            // 후속 조치
            $table->enum('action_taken', ['none', 'rematch', 'counseling', 'medical_referral'])->nullable();
            $table->timestamp('action_taken_at')->nullable();
            $table->unsignedBigInteger('action_taken_by')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['postpartum_client_id', 'assessment_date'], 'uk_epds_client_date');
            $table->index('risk_level');
            $table->index('total_score');

            $table->foreign('postpartum_client_id')
                ->references('id')->on('postpartum_clients')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epds_assessments');
    }
};

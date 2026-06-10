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
        Schema::create('anomaly_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('senior_id')->constrained()->cascadeOnDelete();
            $table->enum('risk_type', ['fall', 'delirium', 'depression', 'nutrition', 'other']);
            $table->decimal('risk_score', 5, 2)->comment('0~100점');
            $table->enum('severity', ['low', 'mid', 'high', 'critical']);
            $table->json('trigger_pattern')->comment('탐지 근거 (Explainable)');
            $table->json('recommendation')->nullable()->comment('대응 가이드');
            $table->enum('status', ['new', 'acknowledged', 'in_progress', 'resolved', 'dismissed'])->default('new');
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['senior_id', 'severity', 'status']);
            $table->index('detected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anomaly_alerts');
    }
};

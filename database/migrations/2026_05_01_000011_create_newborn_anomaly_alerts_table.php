<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('newborn_anomaly_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('newborn_id');
            $table->timestamp('detected_at')->useCurrent();
            $table->enum('risk_type', ['weight_loss', 'jaundice', 'feeding_low', 'temperature', 'overall']);
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            $table->decimal('score', 5, 2)->comment('0~100');
            $table->json('triggers')->comment('발생한 룰 목록');
            $table->json('recommendations')->nullable();

            // 처리
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->text('resolution_note')->nullable();

            $table->index('newborn_id');
            $table->index(['severity', 'resolved_at']);

            $table->foreign('newborn_id')
                ->references('id')->on('newborns')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newborn_anomaly_alerts');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('newborn_daily_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('newborn_id');
            $table->unsignedBigInteger('care_session_id')->nullable();
            $table->dateTime('log_datetime');
            $table->enum('log_type', [
                'feeding', 'diaper', 'sleep', 'weight', 'jaundice', 'temperature', 'note'
            ]);

            // 수유
            $table->enum('feeding_type', [
                'breast_left', 'breast_right', 'bottle_breast', 'bottle_formula'
            ])->nullable();
            $table->integer('feeding_volume_ml')->nullable();
            $table->integer('feeding_duration_min')->nullable();

            // 대소변
            $table->enum('diaper_type', ['urine', 'stool', 'both'])->nullable();
            $table->string('stool_color', 20)->nullable()->comment('yellow/green/dark/bloody');

            // 수면
            $table->dateTime('sleep_start')->nullable();
            $table->dateTime('sleep_end')->nullable();

            // 체중
            $table->integer('weight_g')->nullable();

            // 황달
            $table->integer('jaundice_level')->nullable()->comment('1~5 단계');

            // 체온
            $table->decimal('body_temperature', 3, 1)->nullable();

            // 자유 메모
            $table->text('note_text')->nullable();
            $table->string('note_audio_url', 500)->nullable();

            // AI 분석
            $table->boolean('is_anomaly')->default(false);
            $table->decimal('anomaly_score', 5, 2)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['newborn_id', 'log_datetime']);
            $table->index('log_type');
            $table->index('is_anomaly');

            $table->foreign('newborn_id')
                ->references('id')->on('newborns')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newborn_daily_logs');
    }
};

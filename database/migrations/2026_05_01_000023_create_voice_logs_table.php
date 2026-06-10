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
        Schema::create('voice_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('care_sessions')->cascadeOnDelete();
            $table->string('audio_url', 500)->comment('S3 URL');
            $table->unsignedInteger('duration_sec');
            $table->text('stt_text')->nullable();
            $table->decimal('stt_confidence', 4, 3)->nullable();
            $table->enum('status', ['uploaded', 'transcribing', 'transcribed', 'summarized', 'failed'])->default('uploaded');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voice_logs');
    }
};

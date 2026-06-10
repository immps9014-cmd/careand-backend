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
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('care_sessions')->cascadeOnDelete();
            $table->enum('event_type', ['checkin', 'checkout']);
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('distance_m', 8, 2)->comment('자택과의 거리(m)');
            $table->decimal('accuracy_m', 6, 2)->nullable();
            $table->boolean('is_valid')->default(true)->comment('200m 이내 여부');
            $table->timestamp('logged_at');
            $table->timestamps();

            $table->index(['session_id', 'event_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};

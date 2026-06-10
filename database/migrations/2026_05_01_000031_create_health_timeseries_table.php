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
        Schema::create('health_timeseries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('senior_id')->constrained()->cascadeOnDelete();
            $table->string('metric_name', 50)->comment('meal_pct, sleep_hours, mood_score 등');
            $table->decimal('value', 10, 4);
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['senior_id', 'metric_name', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('health_timeseries');
    }
};

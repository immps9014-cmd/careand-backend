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
        Schema::create('vital_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('senior_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('care_sessions')->nullOnDelete();
            $table->unsignedSmallInteger('blood_pressure_sys')->nullable()->comment('수축기 mmHg');
            $table->unsignedSmallInteger('blood_pressure_dia')->nullable()->comment('이완기 mmHg');
            $table->unsignedSmallInteger('blood_sugar')->nullable()->comment('혈당 mg/dL');
            $table->decimal('body_temperature', 4, 1)->nullable()->comment('체온 °C');
            $table->unsignedSmallInteger('heart_rate')->nullable()->comment('심박수 bpm');
            $table->decimal('weight', 5, 2)->nullable()->comment('체중 kg');
            $table->timestamp('measured_at');
            $table->timestamps();

            $table->index(['senior_id', 'measured_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vital_records');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nursing_patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guardian_id')->constrained('guardians')->cascadeOnDelete();
            $table->string('name', 50);
            $table->date('birth_date');
            $table->enum('gender', ['M', 'F']);
            $table->string('hospital_name', 100);
            $table->string('hospital_address', 255);
            $table->decimal('hospital_lat', 10, 7)->nullable();
            $table->decimal('hospital_lng', 10, 7)->nullable();
            $table->string('ward_room', 50)->nullable()->comment('병동/호실');
            $table->enum('mobility', ['independent', 'assisted', 'bedridden'])
                ->default('assisted')->comment('거동 상태');
            $table->json('diseases')->nullable();
            $table->json('care_requirements')->nullable()->comment('석션/욕창/식사보조/격리 등');
            $table->text('special_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['hospital_lat', 'hospital_lng']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nursing_patients');
    }
};

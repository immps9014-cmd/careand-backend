<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('newborns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('postpartum_client_id');
            $table->string('name', 50);
            $table->enum('gender', ['M', 'F']);
            $table->dateTime('birth_datetime');
            $table->integer('birth_weight_g')->comment('출생 체중 (g)');
            $table->decimal('birth_height_cm', 4, 1)->nullable();
            $table->integer('gestational_age_weeks')->nullable()->comment('재태 주수');
            $table->integer('gestational_age_days')->nullable();
            $table->integer('birth_order')->default(1)->comment('다태아 순번');
            $table->integer('apgar_1min')->nullable();
            $table->integer('apgar_5min')->nullable();
            $table->integer('nicu_days')->nullable();
            $table->json('special_conditions')->nullable();
            $table->boolean('is_alive')->default(true);
            $table->timestamps();

            $table->index('postpartum_client_id');
            $table->index('birth_datetime');

            $table->foreign('postpartum_client_id')
                ->references('id')->on('postpartum_clients')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newborns');
    }
};

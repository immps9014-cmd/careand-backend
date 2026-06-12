<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guardian_id')->constrained('guardians')->cascadeOnDelete();
            $table->string('label', 50)->comment("표시명('우리집' 등)");
            $table->string('address', 255);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->enum('dwelling_type', ['apartment', 'villa', 'house', 'officetel', 'other'])
                ->default('apartment');
            $table->unsignedSmallInteger('size_m2')->nullable();
            $table->boolean('has_pets')->default(false);
            $table->text('entry_note')->nullable()->comment('출입방법/주차');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_addresses');
    }
};

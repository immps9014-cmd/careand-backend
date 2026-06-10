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
        Schema::create('seniors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guardian_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()->comment('어르신 본인 계정 (선택)');
            $table->string('name', 50);
            $table->date('birth_date');
            $table->enum('gender', ['M', 'F']);
            $table->tinyInteger('care_grade')->comment('1~5등급, 0=등급외');
            $table->string('care_grade_no', 30)->nullable()->comment('장기요양인정번호');
            $table->json('diseases')->nullable()->comment('질환 목록 (치매, 당뇨 등)');
            $table->text('special_notes')->nullable();
            $table->string('home_address');
            $table->decimal('home_lat', 10, 7)->nullable();
            $table->decimal('home_lng', 10, 7)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('care_grade');
            $table->index(['home_lat', 'home_lng']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seniors');
    }
};

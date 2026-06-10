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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('biz_no', 12)->unique()->comment('사업자등록번호');
            $table->string('name', 100);
            $table->string('representative', 50);
            $table->string('address');
            $table->string('contact_phone', 20);
            $table->enum('biz_type', ['care_center', 'staffing', 'other']);
            $table->json('certifications')->nullable()->comment('자격증 목록');
            $table->enum('status', ['pending', 'active', 'suspended'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};

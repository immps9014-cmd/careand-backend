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
        Schema::create('caregivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('org_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->date('birth_date');
            $table->enum('gender', ['M', 'F']);
            $table->string('license_no', 30)->comment('요양보호사 자격번호');
            $table->date('license_issued_at');
            $table->timestamp('license_verified_at')->nullable()->comment('보건복지부 진위확인 시각');
            $table->json('specialties')->nullable()->comment('특기: 치매, 당뇨, 뇌졸중 등');
            $table->string('base_address');
            $table->decimal('base_lat', 10, 7)->nullable();
            $table->decimal('base_lng', 10, 7)->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0)->comment('평점 평균');
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('completed_sessions')->default(0)->comment('완료 세션 수');
            $table->tinyInteger('grade_level')->default(1)->comment('1~5 등급 (플랫폼 자체)');
            $table->enum('status', ['pending', 'active', 'suspended', 'leave', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('rating_avg');
            $table->index(['base_lat', 'base_lng']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('caregivers');
    }
};

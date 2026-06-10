<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 200);
            $table->enum('category', [
                'mother_newborn', 'postpartum_grade1', 'housekeeping',
                'caregiver_grade1', 'mentor', 'other'
            ]);
            $table->text('description')->nullable();
            $table->integer('total_hours');
            $table->decimal('passing_attendance_rate', 5, 2)->default(80.00);
            $table->string('issuing_certification', 200)->nullable();
            $table->string('related_govt_qualification', 200)->nullable();
            $table->boolean('is_govt_supported')->default(false);
            $table->string('govt_support_program', 100)->nullable();
            $table->decimal('tuition_fee', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('category');
        });

        // 4개 기본 과정 시드
        \DB::table('courses')->insert([
            ['code' => 'MNH-2026', 'name' => '산모신생아 건강관리사 양성과정',
             'category' => 'mother_newborn', 'total_hours' => 80,
             'issuing_certification' => '산모신생아 건강관리사 수료증',
             'related_govt_qualification' => '보건복지부 산모·신생아 건강관리사',
             'is_govt_supported' => true, 'govt_support_program' => 'tomorrow_card',
             'tuition_fee' => 0, 'is_active' => true,
             'created_at' => now(), 'updated_at' => now()],
            ['code' => 'PPC1-2026', 'name' => '산후관리사 1급',
             'category' => 'postpartum_grade1', 'total_hours' => 200,
             'issuing_certification' => '산후관리사 1급 자격증',
             'related_govt_qualification' => null,
             'is_govt_supported' => true, 'govt_support_program' => 'national_tomorrow_card',
             'tuition_fee' => 600000, 'is_active' => true,
             'created_at' => now(), 'updated_at' => now()],
            ['code' => 'HK-2026', 'name' => '가사관리 과정',
             'category' => 'housekeeping', 'total_hours' => 60,
             'issuing_certification' => '가사관리 수료증',
             'related_govt_qualification' => null,
             'is_govt_supported' => true, 'govt_support_program' => 'tomorrow_card',
             'tuition_fee' => 0, 'is_active' => true,
             'created_at' => now(), 'updated_at' => now()],
            ['code' => 'CG1-2026', 'name' => '요양보호사 1급',
             'category' => 'caregiver_grade1', 'total_hours' => 240,
             'issuing_certification' => '요양보호사 1급',
             'related_govt_qualification' => '국가자격 요양보호사 1급',
             'is_govt_supported' => true, 'govt_support_program' => 'national_tomorrow_card',
             'tuition_fee' => 800000, 'is_active' => true,
             'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};

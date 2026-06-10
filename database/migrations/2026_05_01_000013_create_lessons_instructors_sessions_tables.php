<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // course_lessons
        Schema::create('course_lessons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->integer('sequence');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->integer('duration_min');
            $table->enum('lesson_type', ['lecture', 'practice', 'exam', 'discussion']);
            $table->boolean('is_mandatory')->default(true);
            $table->string('video_url', 500)->nullable();
            $table->string('materials_url', 500)->nullable();

            $table->unique(['course_id', 'sequence']);
            $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
        });

        // instructors
        Schema::create('instructors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('promoted_from_caregiver_id')->nullable()
                ->comment('인력 → 강사 환류');
            $table->text('bio')->nullable();
            $table->json('qualifications')->nullable();
            $table->json('teaching_categories')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->integer('total_students')->default(0);
            $table->enum('status', ['active', 'paused', 'retired'])->default('active');
            $table->timestamps();

            $table->unique('user_id');
            $table->index('status');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('promoted_from_caregiver_id')->references('id')->on('caregivers');
        });

        // course_sessions
        Schema::create('course_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('instructor_id')->nullable();
            $table->string('cohort_name', 50)->comment('예: 2026-1기');
            $table->integer('capacity')->default(30);
            $table->integer('enrolled_count')->default(0);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('classroom_address', 500)->nullable();
            $table->decimal('classroom_lat', 10, 7)->nullable()->comment('GPS 검증');
            $table->decimal('classroom_lng', 10, 7)->nullable();
            $table->integer('classroom_radius_m')->default(50);
            $table->string('schedule_pattern', 200)->nullable();
            $table->enum('status', [
                'planned', 'recruiting', 'ongoing', 'completed', 'cancelled'
            ])->default('planned');
            $table->timestamps();

            $table->index('course_id');
            $table->index('branch_id');
            $table->index('instructor_id');
            $table->index(['start_date', 'end_date']);
            $table->index('status');

            $table->foreign('course_id')->references('id')->on('courses');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('instructor_id')->references('id')->on('instructors');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_sessions');
        Schema::dropIfExists('instructors');
        Schema::dropIfExists('course_lessons');
    }
};

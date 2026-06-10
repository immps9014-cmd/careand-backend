<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // assessments
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_session_id');
            $table->unsignedBigInteger('course_lesson_id')->nullable();
            $table->string('title', 200);
            $table->enum('assessment_type', [
                'quiz', 'midterm', 'final', 'practical_video', 'assignment'
            ]);
            $table->decimal('max_score', 5, 2)->default(100);
            $table->decimal('passing_score', 5, 2)->nullable();
            $table->decimal('weight', 5, 2)->default(1.00);
            $table->timestamp('released_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('course_session_id');
            $table->index('course_lesson_id');
            $table->index('assessment_type');

            $table->foreign('course_session_id')->references('id')->on('course_sessions')->onDelete('cascade');
            $table->foreign('course_lesson_id')->references('id')->on('course_lessons');
        });

        // assessment_submissions
        Schema::create('assessment_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('assessment_id');
            $table->unsignedBigInteger('enrollment_id');
            $table->timestamp('submitted_at')->useCurrent();
            $table->decimal('score', 5, 2)->nullable();

            // 자동/수동 채점
            $table->boolean('auto_graded')->default(false);
            $table->json('answers_json')->nullable();

            // 실습 동영상
            $table->string('submission_video_url', 500)->nullable();
            $table->text('submission_text')->nullable();

            // 강사 채점
            $table->unsignedBigInteger('graded_by')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->text('grader_comment')->nullable();
            $table->json('rubric_scores')->nullable();

            $table->unique(['assessment_id', 'enrollment_id']);
            $table->index('enrollment_id');

            $table->foreign('assessment_id')->references('id')->on('assessments')->onDelete('cascade');
            $table->foreign('enrollment_id')->references('id')->on('enrollments')->onDelete('cascade');
        });

        // certifications
        Schema::create('certifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->string('cert_name', 200);
            $table->enum('cert_type', ['national', 'private', 'completion']);
            $table->string('cert_issuer', 200);
            $table->string('cert_number', 100)->nullable();
            $table->date('issued_date');
            $table->date('expires_date')->nullable();
            $table->string('cert_image_url', 500)->nullable();
            $table->boolean('verified')->default(false);
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('enrollment_id');
            $table->index('expires_date');

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('enrollment_id')->references('id')->on('enrollments');
        });

        // govt_education_supports
        Schema::create('govt_education_supports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('enrollment_id');
            $table->enum('support_type', [
                'tomorrow_card', 'national_tomorrow_card', 'employment_promotion', 'other'
            ]);
            $table->string('application_number', 100)->nullable()->comment('HRD-Net 신청번호');
            $table->date('application_date')->nullable();
            $table->enum('approval_status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->decimal('approved_amount', 10, 2)->nullable();
            $table->decimal('self_pay_amount', 10, 2)->nullable();
            $table->json('hrd_response')->nullable();
            $table->timestamps();

            $table->unique('enrollment_id');
            $table->index('approval_status');

            $table->foreign('enrollment_id')->references('id')->on('enrollments')->onDelete('cascade');
        });

        // instructor_reviews
        Schema::create('instructor_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('instructor_id');
            $table->integer('rating')->comment('1-5');
            $table->text('review')->nullable();
            $table->boolean('is_anonymous')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['enrollment_id', 'instructor_id']);
            $table->index('instructor_id');

            $table->foreign('enrollment_id')->references('id')->on('enrollments')->onDelete('cascade');
            $table->foreign('instructor_id')->references('id')->on('instructors');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_reviews');
        Schema::dropIfExists('govt_education_supports');
        Schema::dropIfExists('certifications');
        Schema::dropIfExists('assessment_submissions');
        Schema::dropIfExists('assessments');
    }
};

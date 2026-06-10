<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // enrollments — 수료 시 caregivers 자동 등록 트리거 핵심 테이블
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_session_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('enrolled_at')->useCurrent();

            // 정부지원
            $table->enum('govt_support_type', [
                'none', 'tomorrow_card', 'national_tomorrow_card', 'employment_promotion'
            ])->default('none');
            $table->string('govt_support_application_id', 100)->nullable()->comment('HRD-Net 신청번호');

            // 결제
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->unsignedBigInteger('payment_id')->nullable();

            // 진행 상태
            $table->enum('status', [
                'pending', 'active', 'completed', 'dropped', 'cancelled'
            ])->default('pending');
            $table->decimal('attendance_rate', 5, 2)->default(0);
            $table->decimal('progress_rate', 5, 2)->default(0);
            $table->decimal('final_score', 5, 2)->nullable();
            $table->timestamp('completed_at')->nullable();

            // 수료 → 인력풀 자동 등록 (★핵심)
            $table->unsignedBigInteger('auto_registered_caregiver_id')->nullable()
                ->comment('수료 후 자동 생성된 caregiver_id');
            $table->timestamp('auto_registered_at')->nullable();

            $table->timestamps();

            $table->unique(['course_session_id', 'user_id']);
            $table->index('user_id');
            $table->index('status');
            $table->index('auto_registered_caregiver_id');

            $table->foreign('course_session_id')->references('id')->on('course_sessions');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('auto_registered_caregiver_id')->references('id')->on('caregivers');
        });

        // class_attendance_logs — 수강생 출결 (Phase 1 attendance_logs와 구분)
        Schema::create('class_attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('course_lesson_id')->nullable();
            $table->date('attendance_date');
            $table->enum('attendance_status', [
                'present', 'late', 'left_early', 'absent', 'excused'
            ]);

            // 체크인 (3중 검증: QR + 셀카 + GPS)
            $table->timestamp('check_in_at')->nullable();
            $table->enum('check_in_method', ['qr', 'manual', 'biometric'])->nullable();
            $table->decimal('check_in_lat', 10, 7)->nullable();
            $table->decimal('check_in_lng', 10, 7)->nullable();
            $table->integer('check_in_distance_m')->nullable();
            $table->string('check_in_selfie_url', 500)->nullable();
            $table->decimal('face_match_score', 5, 4)->nullable();

            // 체크아웃
            $table->timestamp('check_out_at')->nullable();
            $table->enum('check_out_method', ['qr', 'manual', 'biometric'])->nullable();

            // 사유
            $table->text('excuse_reason')->nullable();
            $table->unsignedBigInteger('excuse_approved_by')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['enrollment_id', 'attendance_date'], 'uk_class_enroll_date');
            $table->index('attendance_status');
            $table->index('course_lesson_id');

            $table->foreign('enrollment_id', 'fk_class_att_enroll')
                ->references('id')->on('enrollments')->onDelete('cascade');
            $table->foreign('course_lesson_id', 'fk_class_att_lesson')
                ->references('id')->on('course_lessons');
        });

        // progress_tracks — 동영상 시청 진도
        Schema::create('progress_tracks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('course_lesson_id');
            $table->decimal('progress_pct', 5, 2)->default(0);
            $table->integer('last_position_sec')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('total_watch_seconds')->default(0);
            $table->timestamps();

            $table->unique(['enrollment_id', 'course_lesson_id']);
            $table->foreign('enrollment_id')->references('id')->on('enrollments')->onDelete('cascade');
            $table->foreign('course_lesson_id')->references('id')->on('course_lessons');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progress_tracks');
        Schema::dropIfExists('class_attendance_logs');
        Schema::dropIfExists('enrollments');
    }
};

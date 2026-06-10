<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // caregiver_resumes — AI 이력서
        Schema::create('caregiver_resumes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('caregiver_id');
            $table->integer('version')->default(1);
            $table->boolean('is_current')->default(true);

            // AI 생성 컨텐츠
            $table->string('headline', 200)->nullable();
            $table->text('self_introduction')->nullable();
            $table->json('strengths')->nullable();
            $table->json('weaknesses_for_improvement')->nullable();
            $table->text('experience_summary')->nullable();

            // 도메인별 카드
            $table->text('senior_card_content')->nullable();
            $table->text('postpartum_card_content')->nullable();

            // AI 생성 메타
            $table->string('generated_by_model', 50)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->string('pdf_url', 500)->nullable();

            // 검토
            $table->boolean('reviewed_by_caregiver')->default(false);
            $table->timestamp('reviewed_at')->nullable();
            $table->text('caregiver_comments')->nullable();

            $table->timestamps();

            $table->index(['caregiver_id', 'is_current']);
            $table->foreign('caregiver_id')->references('id')->on('caregivers')->onDelete('cascade');
        });

        // mock_interviews — AI 모의 면접 세션
        Schema::create('mock_interviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('caregiver_id');
            $table->enum('target_domain', ['senior', 'postpartum']);
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->integer('question_count')->default(0);

            // 5영역 평가
            $table->decimal('score_specificity', 3, 2)->nullable()->comment('구체성 1-5');
            $table->decimal('score_warmth', 3, 2)->nullable()->comment('따뜻함');
            $table->decimal('score_expertise', 3, 2)->nullable()->comment('전문성');
            $table->decimal('score_emergency', 3, 2)->nullable()->comment('응급 대응');
            $table->decimal('score_communication', 3, 2)->nullable()->comment('커뮤니케이션');
            $table->decimal('overall_score', 3, 2)->nullable();

            $table->text('feedback_report')->nullable();
            $table->json('recommended_lessons')->nullable();

            $table->index('caregiver_id');
            $table->index('overall_score');
            $table->foreign('caregiver_id')->references('id')->on('caregivers')->onDelete('cascade');
        });

        // mock_interview_qa — Q&A
        Schema::create('mock_interview_qa', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mock_interview_id');
            $table->text('question_text');
            $table->enum('question_category', [
                'greeting', 'experience', 'emergency', 'communication', 'difficult_case', 'self_intro'
            ]);

            $table->text('answer_text')->nullable();
            $table->string('answer_audio_url', 500)->nullable();
            $table->integer('answer_duration_sec')->nullable();

            // 영역별 평가
            $table->decimal('eval_specificity', 3, 2)->nullable();
            $table->decimal('eval_warmth', 3, 2)->nullable();
            $table->decimal('eval_expertise', 3, 2)->nullable();
            $table->decimal('eval_emergency', 3, 2)->nullable();
            $table->decimal('eval_communication', 3, 2)->nullable();

            $table->text('sample_answer')->nullable();
            $table->text('feedback')->nullable();

            $table->timestamp('asked_at')->useCurrent();
            $table->timestamp('answered_at')->nullable();

            $table->index('mock_interview_id');
            $table->index('question_category');
            $table->foreign('mock_interview_id')->references('id')->on('mock_interviews')->onDelete('cascade');
        });

        // mentor_pairings
        Schema::create('mentor_pairings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mentor_caregiver_id');
            $table->unsignedBigInteger('mentee_caregiver_id');
            $table->date('started_at');
            $table->date('ended_at')->nullable();
            $table->enum('status', ['active', 'completed', 'terminated'])->default('active');

            $table->decimal('monthly_incentive', 10, 2)->default(0);
            $table->decimal('total_paid', 10, 2)->default(0);

            $table->integer('sessions_observed')->default(0)->comment('동반 출근');
            $table->integer('video_calls')->default(0);
            $table->integer('mentee_first_30days_sessions')->default(0);
            $table->decimal('mentee_first_30days_avg_rating', 3, 2)->nullable();

            $table->integer('mentor_self_rating')->nullable();
            $table->integer('mentee_rating_of_mentor')->nullable();
            $table->text('closing_note')->nullable();

            $table->timestamps();

            $table->index('mentor_caregiver_id');
            $table->index('mentee_caregiver_id');
            $table->index('status');

            $table->foreign('mentor_caregiver_id')->references('id')->on('caregivers');
            $table->foreign('mentee_caregiver_id')->references('id')->on('caregivers');
        });

        // career_milestones
        Schema::create('career_milestones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('caregiver_id');
            $table->enum('milestone_type', [
                'track_promoted', 'track_demoted', 'cert_acquired', 'mentor_qualified',
                'instructor_recommended', 'first_match',
                '100_sessions', '300_sessions', '500_sessions'
            ]);
            $table->string('from_track', 50)->nullable();
            $table->string('to_track', 50)->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['caregiver_id', 'occurred_at']);
            $table->index('milestone_type');
            $table->foreign('caregiver_id')->references('id')->on('caregivers')->onDelete('cascade');
        });

        // self_introduction_interviews
        Schema::create('self_introduction_interviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('caregiver_id');
            $table->string('interview_audio_url', 500);
            $table->integer('duration_sec');

            // STT 결과
            $table->text('transcript')->nullable();
            $table->decimal('transcript_confidence', 5, 4)->nullable();

            // LLM 추출
            $table->text('extracted_motivation')->nullable();
            $table->json('extracted_strengths')->nullable();
            $table->enum('extracted_target_domain', ['senior', 'postpartum', 'both'])->nullable();
            $table->json('extracted_keywords')->nullable();

            $table->unsignedBigInteger('used_for_resume_id')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('caregiver_id');
            $table->index('used_for_resume_id');

            $table->foreign('caregiver_id')->references('id')->on('caregivers')->onDelete('cascade');
            $table->foreign('used_for_resume_id')->references('id')->on('caregiver_resumes')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('self_introduction_interviews');
        Schema::dropIfExists('career_milestones');
        Schema::dropIfExists('mentor_pairings');
        Schema::dropIfExists('mock_interview_qa');
        Schema::dropIfExists('mock_interviews');
        Schema::dropIfExists('caregiver_resumes');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 ALTER: caregivers 테이블에 4도메인 공유 자산 컬럼 추가
 * - branch_id: 소속 지점
 * - service_domains: 활동 도메인 (senior, postpartum)
 * - career_track: 5단계 커리어 트랙
 * - mentor_caregiver_id: 담당 멘토 (자기 참조)
 * - can_be_mentor: 멘토 자격 보유 여부
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('caregivers', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')
                ->nullable()
                ->after('base_lng')
                ->comment('소속 지점');

            $table->set('service_domains', ['senior', 'postpartum'])
                ->default('senior')
                ->after('branch_id')
                ->comment('활동 도메인 SET');

            $table->enum('career_track', ['rookie', 'settled', 'excellent', 'premium', 'instructor'])
                ->default('rookie')
                ->after('service_domains')
                ->comment('커리어 트랙 5단계');

            $table->unsignedBigInteger('mentor_caregiver_id')
                ->nullable()
                ->after('career_track')
                ->comment('담당 멘토 인력');

            $table->boolean('can_be_mentor')
                ->default(false)
                ->after('mentor_caregiver_id')
                ->comment('멘토 자격 보유');

            $table->index('branch_id', 'idx_caregivers_branch');
            $table->index('service_domains', 'idx_caregivers_service_domains');
            $table->index('career_track', 'idx_caregivers_career_track');

            $table->foreign('mentor_caregiver_id', 'fk_caregivers_mentor')
                ->references('id')->on('caregivers')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('caregivers', function (Blueprint $table) {
            $table->dropForeign('fk_caregivers_mentor');
            $table->dropIndex('idx_caregivers_branch');
            $table->dropIndex('idx_caregivers_service_domains');
            $table->dropIndex('idx_caregivers_career_track');

            $table->dropColumn([
                'branch_id',
                'service_domains',
                'career_track',
                'mentor_caregiver_id',
                'can_be_mentor',
            ]);
        });
    }
};

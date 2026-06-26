<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 돌봄전문가가 기피(거절)한 보호대상 — 검색/자동매칭 양쪽서 영구 제외
        Schema::create('caregiver_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caregiver_id')->constrained('caregivers')->cascadeOnDelete();
            // 대상 추상화: service_domain(senior/nursing/housekeeping) + recipient id
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id');
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['caregiver_id', 'target_type', 'target_id'], 'caregiver_block_unique');
            $table->index(['target_type', 'target_id']);
        });

        // match_candidates 출처 구분: ai=시스템 추천, self=간병인 직접 지원
        if (!Schema::hasColumn('match_candidates', 'source')) {
            Schema::table('match_candidates', function (Blueprint $table) {
                $table->string('source', 8)->default('ai')->after('caregiver_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('caregiver_blocks');
        if (Schema::hasColumn('match_candidates', 'source')) {
            Schema::table('match_candidates', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};

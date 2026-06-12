<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 간병(nursing) 버티컬 — 다세션(교대) 지원 + 카테고리 도메인 매핑.
     * care_sessions.scheduled_*는 NULL이면 기존처럼 match 일정을 따른다(하위호환).
     */
    public function up(): void
    {
        Schema::table('care_sessions', function (Blueprint $table) {
            $table->timestamp('scheduled_start')->nullable()->after('match_id')
                ->comment('세션 예정 시작(다세션용, NULL=match 일정)');
            $table->timestamp('scheduled_end')->nullable()->after('scheduled_start');
            $table->index('scheduled_start');
        });

        Schema::table('service_categories', function (Blueprint $table) {
            $table->string('domain', 20)->default('senior')->after('code')
                ->comment('서비스 도메인(senior/nursing/housekeeping)');
        });

        DB::table('service_categories')->where('code', 'NURSING_HOSPITAL')
            ->update(['domain' => 'nursing']);
        DB::table('service_categories')->whereIn('code', ['HK_CLEANING', 'HK_REPAIR', 'HK_ORGANIZING'])
            ->update(['domain' => 'housekeeping']);
    }

    public function down(): void
    {
        Schema::table('care_sessions', function (Blueprint $table) {
            $table->dropIndex(['scheduled_start']);
            $table->dropColumn(['scheduled_start', 'scheduled_end']);
        });

        Schema::table('service_categories', function (Blueprint $table) {
            $table->dropColumn('domain');
        });
    }
};

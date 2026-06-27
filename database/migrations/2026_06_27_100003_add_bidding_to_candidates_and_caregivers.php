<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 가격 레이어 Phase 2 — 역경매 입찰.
 *
 * - match_candidates: 후보(돌봄전문가)가 제시하는 입찰 시급/메모/상태.
 * - caregivers: 표준 희망단가(default_rate)와 자동입찰(auto_bid) 설정.
 *
 * response(pending/accepted/rejected/expired)는 그대로 두고, 입찰 단계는 bid_status로 분리.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_candidates', function (Blueprint $table) {
            $table->decimal('bid_hourly', 10, 2)->nullable()->after('ai_reasons')
                ->comment('돌봄전문가 입찰 시급');
            $table->string('bid_note', 255)->nullable()->after('bid_hourly')
                ->comment('입찰 메모(경력/조건 어필)');
            $table->enum('bid_status', ['none', 'invited', 'bid', 'withdrawn'])
                ->default('none')->after('bid_note')
                ->comment('none=입찰무관, invited=입찰요청됨, bid=입찰완료, withdrawn=철회');
            $table->timestamp('bid_at')->nullable()->after('bid_status');
        });

        Schema::table('caregivers', function (Blueprint $table) {
            $table->decimal('default_rate', 10, 2)->nullable()->after('grade_level')
                ->comment('표준 희망 시급(입찰 프리필/자동입찰)');
            $table->boolean('auto_bid')->default(false)->after('default_rate')
                ->comment('초대 시 default_rate로 자동 입찰');
        });
    }

    public function down(): void
    {
        Schema::table('match_candidates', function (Blueprint $table) {
            $table->dropColumn(['bid_hourly', 'bid_note', 'bid_status', 'bid_at']);
        });
        Schema::table('caregivers', function (Blueprint $table) {
            $table->dropColumn(['default_rate', 'auto_bid']);
        });
    }
};

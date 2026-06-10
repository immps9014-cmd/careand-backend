<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * match_requests ALTER: 산후 도메인 통합
 * - service_domain: senior 또는 postpartum
 * - postpartum_client_id: 산후 도메인 시 산모 ID
 *
 * postpartum_clients FK는 해당 테이블 생성 후 별도 마이그레이션으로 추가
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('match_requests', function (Blueprint $table) {
            $table->enum('service_domain', ['senior', 'postpartum'])
                ->default('senior')
                ->after('mode')
                ->comment('서비스 도메인');

            $table->unsignedBigInteger('postpartum_client_id')
                ->nullable()
                ->after('senior_id')
                ->comment('산모 ID (postpartum 도메인 시)');

            $table->index('service_domain', 'idx_match_requests_service_domain');
            $table->index('postpartum_client_id', 'idx_match_requests_pp_client');
        });
    }

    public function down(): void
    {
        Schema::table('match_requests', function (Blueprint $table) {
            $table->dropIndex('idx_match_requests_service_domain');
            $table->dropIndex('idx_match_requests_pp_client');
            $table->dropColumn(['service_domain', 'postpartum_client_id']);
        });
    }
};

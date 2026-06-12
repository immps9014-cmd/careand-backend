<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_requests', function (Blueprint $table) {
            $table->foreignId('nursing_patient_id')->nullable()->after('postpartum_client_id')
                ->comment('간병 환자 ID (nursing 도메인 시)')
                ->constrained('nursing_patients');
            $table->foreignId('service_address_id')->nullable()->after('nursing_patient_id')
                ->comment('가사 주소 ID (housekeeping 도메인 시)')
                ->constrained('service_addresses');
            $table->json('requirements')->nullable()->after('special_request')
                ->comment('도메인별 가변 요구사항(교대형태/석션, 평수/사진요구 등)');
        });
    }

    public function down(): void
    {
        Schema::table('match_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('nursing_patient_id');
            $table->dropConstrainedForeignId('service_address_id');
            $table->dropColumn('requirements');
        });
    }
};

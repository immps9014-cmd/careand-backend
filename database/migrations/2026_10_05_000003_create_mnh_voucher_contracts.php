<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 산모신생아 건강관리 바우처 기간형 계약(요구사항 CAREN-MNH-01 2단계, 2026-10-05).
 * - mnh_support_types: 복지부 연도별 지원유형 기준표. 금액은 운영자가 고시 원문을 보고 입력한다(시스템이 만들지 않음).
 * - mnh_contracts: 케어앤(제공기관)과 이용자의 5~40일 계약. 유형·금액은 계약 시점 값으로 복사(기준표가 바뀌어도 계약은 유지).
 * - mnh_contract_events: 선납·배정·교체·연기·특이사항 이력(달력 표기 겸 감사 기록).
 * - match_requests.mnh_contract_id: 배정 때 만드는 정기 요청 → 세션·출근·일지는 기존 경로를 그대로 탄다.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mnh_support_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->string('fetus_type', 20)->comment('single|twins|triplets_plus');
            $table->string('birth_order', 20)->comment('first|second|third_plus|any');
            $table->string('income_tier', 30)->comment('고시 유형명 그대로 예: A-가형');
            $table->string('period', 20)->comment('short|standard|extended');
            $table->unsignedTinyInteger('days');
            $table->unsignedInteger('total_price')->comment('서비스 가격(원)');
            $table->unsignedInteger('gov_support')->comment('정부지원금(원)');
            $table->unsignedInteger('self_pay')->comment('본인부담금(원)');
            $table->string('note', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['year', 'fetus_type', 'birth_order', 'income_tier', 'period'], 'uq_mnh_support_type');
        });

        Schema::create('mnh_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_no', 20)->unique();
            $table->unsignedBigInteger('postpartum_client_id');
            $table->unsignedBigInteger('user_id')->comment('신청 회원');
            $table->unsignedBigInteger('support_type_id')->nullable();
            $table->unsignedSmallInteger('year');
            $table->string('fetus_type', 20)->nullable();
            $table->string('birth_order', 20)->nullable();
            $table->string('income_tier', 30)->nullable();
            $table->string('period', 20)->nullable();
            $table->unsignedTinyInteger('days');
            $table->unsignedInteger('total_price')->nullable();
            $table->unsignedInteger('gov_support')->nullable();
            $table->unsignedInteger('self_pay')->nullable();
            $table->date('start_date')->comment('서비스 개시일(KST 날짜)');
            $table->json('weekdays')->nullable()->comment('제공 요일 ISO 1=월..7=일');
            $table->json('skip_dates')->nullable()->comment('제공하지 않는 날(공휴일·행사·연기)');
            $table->string('daily_start', 5)->default('09:00');
            $table->unsignedSmallInteger('daily_minutes')->default(480);
            $table->string('payment_method', 20)->comment('cash|card|local_currency');
            $table->unsignedInteger('prepaid_amount')->nullable();
            $table->timestamp('prepaid_at')->nullable();
            $table->string('prepaid_receipt_no', 50)->nullable();
            $table->unsignedBigInteger('prepaid_by')->nullable();
            $table->string('status', 20)->default('applied')->comment('applied|confirmed|active|completed|cancelled');
            $table->unsignedBigInteger('match_request_id')->nullable();
            $table->unsignedBigInteger('caregiver_id')->nullable()->comment('현재 담당');
            $table->text('member_note')->nullable();
            $table->text('admin_note')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();
            $table->index('postpartum_client_id');
            $table->index('user_id');
            $table->index(['status', 'start_date']);
            $table->index('caregiver_id');
        });

        Schema::create('mnh_contract_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->string('type', 20);
            $table->date('event_date')->nullable()->comment('달력에 표기할 날(KST)');
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['contract_id', 'created_at']);
        });

        Schema::table('match_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('mnh_contract_id')->nullable();
            $table->index('mnh_contract_id');
        });
    }

    public function down(): void
    {
        Schema::table('match_requests', function (Blueprint $table) {
            $table->dropIndex(['mnh_contract_id']);
            $table->dropColumn('mnh_contract_id');
        });
        Schema::dropIfExists('mnh_contract_events');
        Schema::dropIfExists('mnh_contracts');
        Schema::dropIfExists('mnh_support_types');
    }
};

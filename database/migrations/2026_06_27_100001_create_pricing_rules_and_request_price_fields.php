<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 가격 레이어 Phase 1 — 적정 간병비 산출 기반 스키마.
 *
 * - pricing_rules: 카테고리(×지역)별 가격 산출 룰(지역계수/시간대·긴급 배수/난이도 가산/최저시급).
 * - match_requests: 요청 시점 적정가 스냅샷(price_estimate)과 보호자 희망 상한(budget_hourly).
 *
 * 동작 무변경 단계: 합의가(matches.hourly_rate)는 기존 category.base_rate 경로를 그대로 사용한다.
 * 본 마이그레이션은 산출/저장만 추가하며 역경매·합의가 교체는 후속 Phase에서 처리한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('service_categories')->cascadeOnDelete();
            $table->string('region_code', 16)->nullable()->comment('시군구 코드, null=전국 기본');
            $table->decimal('region_index', 4, 2)->default(1.00)->comment('지역 물가 계수');
            $table->decimal('night_mult', 4, 2)->default(1.30)->comment('야간(22-06) 배수');
            $table->decimal('holiday_mult', 4, 2)->default(1.50)->comment('공휴일/일요일 배수');
            $table->decimal('emergency_mult', 4, 2)->default(1.20)->comment('긴급 요청 배수');
            $table->json('acuity_addons')->nullable()->comment('{치매:1500, 와상:2000, 석션:3000} 시급 가산');
            $table->decimal('min_hourly', 10, 2)->default(10030)->comment('법정 최저시급 하한');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['category_id', 'region_code']);
        });

        Schema::table('match_requests', function (Blueprint $table) {
            $table->json('price_estimate')->nullable()->after('requirements')
                ->comment('요청 시점 적정가 스냅샷 {floor,suggested,ceil,n_samples,inputs,breakdown}');
            $table->decimal('budget_hourly', 10, 2)->nullable()->after('price_estimate')
                ->comment('보호자 희망 상한 시급(선택)');
        });
    }

    public function down(): void
    {
        Schema::table('match_requests', function (Blueprint $table) {
            $table->dropColumn(['price_estimate', 'budget_hourly']);
        });
        Schema::dropIfExists('pricing_rules');
    }
};

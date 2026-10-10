<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 산모신생아 1단계 나머지(CAREN-REF-01, 2026-10-10).
 * - mnh_income_criteria: 기준중위소득 150% 판정 — 가구원수(태아 포함)별 건강보험료 본인부담금 상한(장기요양보험료 제외).
 *   2026 값 출처: 부산 사하구 을숙도보건소 「2026년 건강보험료 본인부담금에 의한 소득 150% 판정기준」(2~10인),
 *   인천시 육아정보 2~5인 대조 일치.
 * - mnh_addon_items: 케어앤 자체 추가요금·대여용품(토·휴일 추가, 큰아이·가족 추가, 유축기 대여 등).
 *   제공기관이 정하는 가격이라 시드하지 않는다 — 관리자 화면에서 입력해야 신청 화면에 나온다.
 * - mnh_contracts.addons / addon_total: 신청 시 고른 항목 스냅샷(가격이 바뀌어도 계약은 유지). 바우처 본인부담과 별도로 낸다.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mnh_income_criteria', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('household_size')->comment('가구원수(태아 포함)');
            $table->unsignedInteger('income_limit')->comment('기준중위소득 150% 월 소득기준(원)');
            $table->unsignedInteger('premium_employee')->comment('직장가입자 건보료 본인부담 상한(원)');
            $table->unsignedInteger('premium_regional')->comment('지역가입자');
            $table->unsignedInteger('premium_mixed')->comment('혼합(직장+지역)');
            $table->timestamps();
            $table->unique(['year', 'household_size']);
        });

        Schema::create('mnh_addon_items', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->comment('extra|rental');
            $table->string('name', 60);
            $table->string('unit_label', 10)->default('회')->comment('수량 단위: 일·명·시간·개');
            $table->unsignedInteger('price')->comment('단위당 금액(원)');
            $table->unsignedTinyInteger('max_qty')->default(1);
            $table->string('note', 200)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('mnh_contracts', function (Blueprint $table) {
            $table->json('addons')->nullable()->comment('신청 시 추가요금·대여 스냅샷 [{id,name,unit_label,price,qty,amount}]');
            $table->unsignedInteger('addon_total')->nullable()->comment('추가요금 합계(원) — 바우처 본인부담과 별도');
        });

        $now = now();
        $rows = [
            [2, 6299000, 229357, 164508, 232890],
            [3, 8039000, 290169, 240352, 296127],
            [4, 9743000, 360410, 322443, 374300],
            [5, 11336000, 410439, 378691, 432308],
            [6, 12834000, 490306, 473662, 535512],
            [7, 14273000, 535512, 525833, 584741],
            [8, 15712000, 584741, 579249, 634423],
            [9, 17151000, 634423, 628429, 712921],
            [10, 18590000, 712921, 697282, 838330],
        ];
        DB::table('mnh_income_criteria')->insertOrIgnore(array_map(fn ($r) => [
            'year' => 2026, 'household_size' => $r[0], 'income_limit' => $r[1],
            'premium_employee' => $r[2], 'premium_regional' => $r[3], 'premium_mixed' => $r[4],
            'created_at' => $now, 'updated_at' => $now,
        ], $rows));
    }

    public function down(): void
    {
        Schema::table('mnh_contracts', function (Blueprint $table) {
            $table->dropColumn(['addons', 'addon_total']);
        });
        Schema::dropIfExists('mnh_addon_items');
        Schema::dropIfExists('mnh_income_criteria');
    }
};

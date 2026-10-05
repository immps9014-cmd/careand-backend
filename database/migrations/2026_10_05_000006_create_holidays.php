<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 공휴일 달력(2026-10-05) — 산모신생아 바우처 제공일에서 공휴일을 자동으로 뺀다.
 * - holidays: 관공서 공휴일(대체공휴일·선거일 포함). 첫 적재는 python holidays 0.83(KR) 2026~2027,
 *   임시공휴일·이후 연도는 관리자 화면에서 넣는다.
 * - mnh_contracts.holiday_work_dates: 공휴일이지만 이 계약은 제공하는 날(관리자 지정).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique()->comment('KST 날짜');
            $table->string('name', 50);
            $table->string('source', 20)->default('admin')->comment('seed|admin');
            $table->timestamps();
        });

        Schema::table('mnh_contracts', function (Blueprint $table) {
            $table->json('holiday_work_dates')->nullable()->comment('공휴일이지만 제공하는 날');
        });

        $now = now();
        $rows = [
            ['2026-01-01', '신정'],
            ['2026-02-16', '설날 전날'],
            ['2026-02-17', '설날'],
            ['2026-02-18', '설날 다음날'],
            ['2026-03-01', '삼일절'],
            ['2026-03-02', '삼일절 대체 휴일'],
            ['2026-05-05', '어린이날'],
            ['2026-05-24', '부처님오신날'],
            ['2026-05-25', '부처님오신날 대체 휴일'],
            ['2026-06-03', '지방선거일'],
            ['2026-06-06', '현충일'],
            ['2026-08-15', '광복절'],
            ['2026-08-17', '광복절 대체 휴일'],
            ['2026-09-24', '추석 전날'],
            ['2026-09-25', '추석'],
            ['2026-09-26', '추석 다음날'],
            ['2026-10-03', '개천절'],
            ['2026-10-05', '개천절 대체 휴일'],
            ['2026-10-09', '한글날'],
            ['2026-12-25', '기독탄신일'],
            ['2027-01-01', '신정'],
            ['2027-02-06', '설날 전날'],
            ['2027-02-07', '설날'],
            ['2027-02-08', '설날 다음날'],
            ['2027-02-09', '설날 대체 휴일'],
            ['2027-03-01', '삼일절'],
            ['2027-05-05', '어린이날'],
            ['2027-05-13', '부처님오신날'],
            ['2027-06-06', '현충일'],
            ['2027-08-15', '광복절'],
            ['2027-08-16', '광복절 대체 휴일'],
            ['2027-09-14', '추석 전날'],
            ['2027-09-15', '추석'],
            ['2027-09-16', '추석 다음날'],
            ['2027-10-03', '개천절'],
            ['2027-10-04', '개천절 대체 휴일'],
            ['2027-10-09', '한글날'],
            ['2027-10-11', '한글날 대체 휴일'],
            ['2027-12-25', '기독탄신일'],
            ['2027-12-27', '기독탄신일 대체 휴일'],
        ];
        DB::table('holidays')->insert(array_map(fn ($r) => ['date' => $r[0], 'name' => $r[1], 'source' => 'seed',
            'created_at' => $now, 'updated_at' => $now], $rows));
    }

    public function down(): void
    {
        Schema::table('mnh_contracts', function (Blueprint $table) {
            $table->dropColumn('holiday_work_dates');
        });
        Schema::dropIfExists('holidays');
    }
};

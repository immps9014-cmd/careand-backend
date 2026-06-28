<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 가입 의도 분리: 보호자(care) vs 가사요청자(housekeeping).
     * 둘 다 role=guardian 이지만 통계/온보딩을 구분하기 위한 마커.
     */
    public function up(): void
    {
        Schema::table('guardians', function (Blueprint $table) {
            $table->string('intent', 20)->default('care')->after('relation')
                ->comment('care=보호자, housekeeping=가사요청자');
            $table->index('intent');
        });

        // 가사요청자는 어르신과의 관계가 없으므로 relation을 nullable로 완화
        DB::statement('ALTER TABLE `guardians` MODIFY `relation` VARCHAR(20) NULL');
    }

    public function down(): void
    {
        Schema::table('guardians', function (Blueprint $table) {
            $table->dropIndex(['intent']);
            $table->dropColumn('intent');
        });
    }
};

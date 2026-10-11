<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 회원 생년월일(선택) — 내 정보 > 계정 정보에서 입력, 「본인이 산모」 등록 때 자동으로 채운다(2026-10-11).
 * MariaDB 10.3 이라 AFTER 없이 끝에 붙인다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->date('birth_date')->nullable()->comment('생년월일(선택, KST 날짜)');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('birth_date');
        });
    }
};

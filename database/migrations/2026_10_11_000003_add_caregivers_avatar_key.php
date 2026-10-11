<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 사진 대신 고른 캐릭터(c1~c8, 2026-10-11) — null 이면 실제 사진이거나 사진 없음. MariaDB 10.3 이라 AFTER 없이 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caregivers', function (Blueprint $t) {
            $t->string('avatar_key', 10)->nullable()->comment('사진 대신 고른 캐릭터 키(public/caregiver-avatars/{key}.jpg)');
        });
    }

    public function down(): void
    {
        Schema::table('caregivers', function (Blueprint $t) {
            $t->dropColumn('avatar_key');
        });
    }
};

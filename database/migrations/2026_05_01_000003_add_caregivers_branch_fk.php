<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * caregivers.branch_id에 branches FK 제약 추가 (branches 생성 후)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('caregivers', function (Blueprint $table) {
            $table->foreign('branch_id', 'fk_caregivers_branch')
                ->references('id')->on('branches')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('caregivers', function (Blueprint $table) {
            $table->dropForeign('fk_caregivers_branch');
        });
    }
};

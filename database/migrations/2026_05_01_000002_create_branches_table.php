<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * branches: 지점 마스터 (직영 4 + 가맹)
 * - 화성(HS), 오산(OS), 수원(SW), 평택(PT) 직영 + 향후 가맹점
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique()->comment('지점 코드 (HS/OS/SW/PT)');
            $table->string('name', 100)->comment('지점명');
            $table->enum('type', ['direct', 'franchise'])->default('direct')->comment('직영/가맹');
            $table->unsignedBigInteger('parent_branch_id')->nullable()->comment('본부 (가맹의 경우)');
            $table->string('address', 500);
            $table->string('address_detail', 200)->nullable();
            $table->string('region_code', 20)->comment('시군구 코드');
            $table->string('phone', 20)->nullable();
            $table->unsignedBigInteger('manager_user_id')->nullable()->comment('지점장');
            $table->string('business_number', 20)->nullable();
            $table->date('opened_at');
            $table->date('closed_at')->nullable();
            $table->enum('status', ['active', 'paused', 'closed'])->default('active');
            $table->timestamps();

            $table->index('region_code');
            $table->index(['type', 'status']);

            $table->foreign('parent_branch_id')->references('id')->on('branches');
        });

        // 직영 4개 지점 시드 데이터
        \DB::table('branches')->insert([
            ['code' => 'HS', 'name' => '화성점', 'type' => 'direct',
             'address' => '경기도 화성시 병점3로 12 2층', 'region_code' => '41590',
             'phone' => '031-375-3525', 'opened_at' => '2016-03-01', 'status' => 'active',
             'created_at' => now(), 'updated_at' => now()],
            ['code' => 'OS', 'name' => '오산점', 'type' => 'direct',
             'address' => '경기도 오산시 대원로 35 2층', 'region_code' => '41370',
             'phone' => null, 'opened_at' => '2018-06-01', 'status' => 'active',
             'created_at' => now(), 'updated_at' => now()],
            ['code' => 'SW', 'name' => '수원점', 'type' => 'direct',
             'address' => '경기도 수원시', 'region_code' => '41110',
             'phone' => null, 'opened_at' => '2019-09-01', 'status' => 'active',
             'created_at' => now(), 'updated_at' => now()],
            ['code' => 'PT', 'name' => '평택점', 'type' => 'direct',
             'address' => '경기 평택시 고덕면 고덕북로 223', 'region_code' => '41220',
             'phone' => null, 'opened_at' => '2020-04-01', 'status' => 'active',
             'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};

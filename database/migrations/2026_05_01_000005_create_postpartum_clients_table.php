<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * postpartum_clients: 산모 클라이언트
 * 출산 정보 + 정부 바우처 통합 관리
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('postpartum_clients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('산모 또는 가족 보호자');
            $table->string('name', 50);
            $table->binary('name_encrypted')->nullable();
            $table->binary('phone_encrypted');
            $table->date('birth_date');
            $table->string('address', 500);
            $table->string('address_detail', 200)->nullable();
            $table->string('region_code', 20);
            $table->unsignedBigInteger('branch_id')->nullable();

            // 출산 정보
            $table->date('delivery_date')->comment('출산일');
            $table->enum('delivery_type', ['natural', 'cesarean', 'vbac'])->comment('자연/제왕/제왕후자연');
            $table->boolean('is_first_baby')->default(true);
            $table->boolean('is_multiple_birth')->default(false);
            $table->enum('breastfeeding_intent', ['exclusive', 'mixed', 'formula', 'undecided'])
                ->default('undecided');

            // 임신·출산 특이사항
            $table->json('pregnancy_complications')->nullable();
            $table->json('postpartum_conditions')->nullable();
            $table->json('medications')->nullable();

            // 정부 바우처
            $table->enum('voucher_grade', ['a_type', 'b_type', 'c_type', 'd_type', 'e_type'])
                ->nullable()->comment('가/나/다/라/마형');
            $table->decimal('voucher_self_pay_rate', 5, 4)->nullable();
            $table->integer('voucher_total_days')->nullable();
            $table->integer('voucher_used_days')->default(0);
            $table->decimal('voucher_amount_total', 12, 2)->nullable();
            $table->decimal('voucher_amount_used', 12, 2)->default(0);
            $table->date('voucher_certified_at')->nullable();

            $table->enum('status', ['active', 'completed', 'cancelled'])->default('active');
            $table->text('special_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('branch_id');
            $table->index('region_code');
            $table->index('delivery_date');
            $table->index('status');

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('branch_id')->references('id')->on('branches');
        });

        // match_requests의 postpartum_client_id FK 추가
        Schema::table('match_requests', function (Blueprint $table) {
            $table->foreign('postpartum_client_id', 'fk_match_requests_pp_client')
                ->references('id')->on('postpartum_clients');
        });
    }

    public function down(): void
    {
        Schema::table('match_requests', function (Blueprint $table) {
            $table->dropForeign('fk_match_requests_pp_client');
        });
        Schema::dropIfExists('postpartum_clients');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 산모신생아 양방향 평가(CAREN-MNH-01 4단계, 2026-10-05).
 * kind: caregiver_to_client(제공인력→이용자) | org_to_caregiver(기관→인력). 이용자→인력은 기존 reviews(1단계 6항목).
 * 별점·CS 후기 화면과 섞이지 않게 reviews 와 따로 둔다.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mnh_evaluations', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->unsignedBigInteger('caregiver_id');
            $table->unsignedBigInteger('postpartum_client_id')->nullable();
            $table->unsignedBigInteger('evaluator_user_id');
            $table->string('timing', 10)->default('interim')->comment('interim|final');
            $table->json('scores');
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->index(['caregiver_id', 'kind']);
            $table->index(['contract_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mnh_evaluations');
    }
};

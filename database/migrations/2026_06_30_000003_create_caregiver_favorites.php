<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 찜한 돌봄전문가 (보호자 ↔ 돌봄전문가).
 * 보호자가 검증된 돌봄전문가 목록에서 찜한 인력을 저장. 추후 돌봄 신청 시 우선 반영.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caregiver_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('caregiver_id')->constrained('caregivers')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'caregiver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caregiver_favorites');
    }
};

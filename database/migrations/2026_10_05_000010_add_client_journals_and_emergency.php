<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 산모신생아 이용자 보강(2026-10-05, 요구사항분석 「이용자 회원가입」 비상연락처 · 「이용자 이용일지」 수시 작성·기관 알림).
 * - postpartum_clients.emergency_contact: {name, relation, phone} JSON 을 MedicalCrypto 로 암호화(인력과 같은 형식).
 *   성별은 산모라 「여」 고정 — 칸을 두지 않고 서류 변수에서만 쓴다.
 * - mnh_client_journals: 산모가 수시로 쓰는 이용일지(아기 수유·배변·수면·체온, 산모 상태, 서비스 의견, 메모).
 *   values 는 숫자·선택값(평문), note 는 건강정보라 암호화. 기관 확인(checked_*) 은 관리자 「이용일지」 탭에서.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('postpartum_clients', function (Blueprint $table) {
            $table->text('emergency_contact')->nullable()->comment('암호화 JSON {name, relation, phone}');
        });

        Schema::create('mnh_client_journals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('postpartum_client_id');
            $table->unsignedBigInteger('newborn_id')->nullable();
            $table->unsignedBigInteger('author_user_id');
            $table->string('kind', 20)->comment('feeding|diaper|sleep|temperature|mother|service|note');
            $table->timestamp('logged_at')->comment('UTC');
            $table->json('values')->nullable();
            $table->text('note')->nullable()->comment('MedicalCrypto 암호문');
            $table->string('flag', 30)->nullable()->comment('기관이 빨리 봐야 할 사유 — baby_fever 등');
            $table->timestamp('checked_at')->nullable();
            $table->unsignedBigInteger('checked_by')->nullable();
            $table->string('check_note', 500)->nullable();
            $table->timestamps();

            $table->index(['postpartum_client_id', 'logged_at']);
            $table->index(['checked_at', 'flag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mnh_client_journals');
        Schema::table('postpartum_clients', function (Blueprint $table) {
            $table->dropColumn('emergency_contact');
        });
    }
};

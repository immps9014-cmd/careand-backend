<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 돌봄전문가 서류 + 정산 계좌 — 기능 9·20(2026-09-28, 구현계획 S5).
 * 파일 본문은 DB 가 아니라 storage/app/caregiver-docs/{caregiver_id}/*.enc (암호화). 계좌번호도 암호화 저장.
 * MariaDB 10.3: 컬럼 추가는 AFTER 없이.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caregiver_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caregiver_id')->constrained()->cascadeOnDelete();
            $table->string('doc_type', 30)->comment('config/caregiver_docs.php types 키');
            $table->string('file_path', 255)->comment('local 디스크 상대경로(암호화 파일)');
            $table->string('original_name', 190)->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size_bytes')->default(0);
            $table->char('sha256', 64)->comment('원본 파일 해시(위변조 확인)');
            $table->string('status', 12)->default('submitted')->comment('submitted|verified|rejected|replaced');
            $table->date('issued_at')->nullable()->comment('서류 발급일(범죄경력·건강진단 유효기간 계산)');
            $table->date('expires_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reject_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['caregiver_id', 'doc_type', 'status']);
        });

        Schema::table('caregivers', function (Blueprint $table) {
            $table->string('bank_name', 40)->nullable()->comment('정산 은행');
            $table->text('bank_account')->nullable()->comment('정산 계좌번호 — MedicalCrypto 암호화');
            $table->string('bank_holder', 40)->nullable()->comment('예금주');
            $table->timestamp('bank_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('caregivers', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'bank_account', 'bank_holder', 'bank_updated_at']);
        });
        Schema::dropIfExists('caregiver_documents');
    }
};

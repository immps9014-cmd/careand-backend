<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 산모신생아 바우처 전자서명 서류(CAREN-MNH-01 3단계, 2026-10-05).
 * - mnh_doc_templates: 서식 판(版) — 고치면 새 판이 생기고, 이미 발행한 서류는 발행 당시 본문(content_html)을 그대로 갖는다.
 * - mnh_documents: 발행·서명된 서류 한 건. 서명 이미지·PDF 는 storage/app/mnh-docs 에 암호화 저장(경로만 DB).
 *   content_hash = 본문+입력값+서명 이미지+서명자·시각의 SHA-256 — 서명 뒤 변조 확인용.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mnh_doc_templates', function (Blueprint $table) {
            $table->id();
            $table->string('doc_type', 30);
            $table->unsignedSmallInteger('version');
            $table->string('title', 100);
            $table->mediumText('body');
            $table->string('note', 300)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['doc_type', 'version']);
        });

        Schema::create('mnh_documents', function (Blueprint $table) {
            $table->id();
            $table->string('doc_type', 30);
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->unsignedBigInteger('caregiver_id')->nullable();
            $table->unsignedBigInteger('care_session_id')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->unsignedSmallInteger('template_version')->nullable();
            $table->string('title', 100);
            $table->mediumText('content_html')->comment('발행 시점 본문 스냅숏');
            $table->json('form_data')->nullable();
            $table->string('status', 10)->default('issued')->comment('issued|signed|void');
            $table->string('signer_role', 10)->comment('client|caregiver');
            $table->unsignedBigInteger('signer_user_id')->nullable()->comment('서명할 회원');
            $table->string('signer_name', 50)->nullable();
            $table->string('signature_path', 255)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->string('signed_ua', 255)->nullable();
            $table->unsignedBigInteger('captured_by')->nullable()->comment('서명을 받은 기기의 회원(제공기록지=관리사)');
            $table->char('content_hash', 64)->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->timestamp('pdf_generated_at')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->timestamps();
            $table->index(['contract_id', 'doc_type']);
            $table->index('caregiver_id');
            $table->index('care_session_id');
            $table->index(['signer_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mnh_documents');
        Schema::dropIfExists('mnh_doc_templates');
    }
};

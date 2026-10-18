<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 서류 보관함 검색(종류·상태·서명일) 색인 — 2026-10-18
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mnh_documents', function (Blueprint $t) {
            $t->index(['doc_type', 'status', 'signed_at'], 'mnh_documents_archive_index');
        });
    }

    public function down(): void
    {
        Schema::table('mnh_documents', function (Blueprint $t) {
            $t->dropIndex('mnh_documents_archive_index');
        });
    }
};

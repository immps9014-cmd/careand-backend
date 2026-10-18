<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 바우처 자동 알림 보낸 기록(2026-10-18) — 제공기록지 누락(kind=provision, ref=care_session_id)·모니터링 설문 재알림(kind=survey, ref=mnh_documents.id).
// 같은 (kind, ref, step) 은 한 번만 보낸다.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mnh_reminders', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 20);
            $t->unsignedBigInteger('ref_id');
            $t->unsignedTinyInteger('step')->default(1);
            $t->dateTime('sent_at');
            $t->unique(['kind', 'ref_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mnh_reminders');
    }
};

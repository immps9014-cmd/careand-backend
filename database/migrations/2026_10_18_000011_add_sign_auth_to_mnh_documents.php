<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 서명할 때의 본인 확인 기록(2026-10-18) — {method: sms|login, phone_tail, verified_at, bundle, bundle_size}
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mnh_documents', function (Blueprint $t) {
            $t->json('sign_auth')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mnh_documents', function (Blueprint $t) {
            $t->dropColumn('sign_auth');
        });
    }
};

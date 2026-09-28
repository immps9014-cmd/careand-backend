<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 정산 명세서 확인·이의제기 — 기능 15 「승인 시 이체, 이의제기는 24시간 내 회신」(2026-09-29, S5 잔여). MariaDB 10.3: AFTER 없이.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->timestamp('caregiver_ack_at')->nullable()->comment('돌봄전문가가 명세서를 확인(승인)한 시각');
            $table->text('dispute_reason')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->string('dispute_status', 10)->nullable()->comment('open|resolved');
            $table->text('dispute_reply')->nullable();
            $table->timestamp('dispute_resolved_at')->nullable();
            $table->foreignId('dispute_resolved_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dispute_resolved_by');
            $table->dropColumn(['caregiver_ack_at', 'dispute_reason', 'disputed_at', 'dispute_status', 'dispute_reply', 'dispute_resolved_at']);
        });
    }
};

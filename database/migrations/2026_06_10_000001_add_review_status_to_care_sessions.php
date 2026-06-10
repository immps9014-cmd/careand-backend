<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_sessions', function (Blueprint $table) {
            $table->enum('review_status', ['pending', 'approved', 'rejected'])
                ->default('pending')
                ->after('status')
                ->comment('AI 일지 검수 상태');
            $table->text('review_note')->nullable()->after('review_status');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('review_note');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('care_sessions', function (Blueprint $table) {
            $table->dropColumn(['review_status', 'review_note', 'reviewed_by', 'reviewed_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->text('admin_reply')->nullable()->after('tags');
            $table->timestamp('replied_at')->nullable()->after('admin_reply');
            $table->unsignedBigInteger('replied_by')->nullable()->after('replied_at');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['admin_reply', 'replied_at', 'replied_by']);
        });
    }
};

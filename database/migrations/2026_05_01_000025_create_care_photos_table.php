<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('care_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('care_sessions')->cascadeOnDelete();
            $table->string('photo_url', 500);
            $table->string('thumbnail_url', 500)->nullable();
            $table->string('caption', 200)->nullable();
            $table->boolean('is_curated')->default(false)->comment('AI가 선별한 베스트 사진');
            $table->timestamp('taken_at');
            $table->timestamps();

            $table->index('session_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('care_photos');
    }
};

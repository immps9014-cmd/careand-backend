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
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users');
            $table->enum('reviewer_role', ['guardian', 'caregiver']);
            $table->tinyInteger('rating')->comment('1~5');
            $table->text('comment')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->unique(['match_id', 'reviewer_id']);
            $table->index(['match_id', 'reviewer_role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};

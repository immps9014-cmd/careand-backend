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
        Schema::create('match_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('match_requests')->cascadeOnDelete();
            $table->foreignId('caregiver_id')->constrained()->cascadeOnDelete();
            $table->decimal('ai_score', 4, 3)->comment('0.000~1.000');
            $table->json('ai_reasons')->comment('Explainable AI: 추천 사유');
            $table->tinyInteger('rank')->comment('1~5순위');
            $table->enum('response', ['pending', 'accepted', 'rejected', 'expired'])->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['request_id', 'caregiver_id']);
            $table->index(['request_id', 'rank']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match_candidates');
    }
};

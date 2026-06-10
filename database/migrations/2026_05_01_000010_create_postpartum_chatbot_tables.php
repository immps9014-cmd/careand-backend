<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('postpartum_chatbot_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('postpartum_client_id');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->integer('message_count')->default(0);

            $table->index('postpartum_client_id');
            $table->foreign('postpartum_client_id')
                ->references('id')->on('postpartum_clients')
                ->onDelete('cascade');
        });

        Schema::create('postpartum_chatbot_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->enum('role', ['user', 'assistant']);
            $table->text('content');
            $table->json('sources')->nullable()->comment('RAG 검색 출처');
            $table->timestamp('created_at')->useCurrent();

            $table->index('session_id');
            $table->foreign('session_id')
                ->references('id')->on('postpartum_chatbot_sessions')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postpartum_chatbot_messages');
        Schema::dropIfExists('postpartum_chatbot_sessions');
    }
};

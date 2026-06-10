<?php

namespace App\Domains\Postpartum\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 산후 RAG 챗봇 세션
 */
class PostpartumChatbotSession extends Model
{
    use HasFactory;

    protected $table = 'postpartum_chatbot_sessions';
    public $timestamps = false;
    protected $dates = ['started_at', 'ended_at'];

    protected $fillable = [
        'postpartum_client_id', 'started_at', 'ended_at', 'message_count',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    public function postpartumClient(): BelongsTo
    {
        return $this->belongsTo(PostpartumClient::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(PostpartumChatbotMessage::class, 'session_id');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }
}

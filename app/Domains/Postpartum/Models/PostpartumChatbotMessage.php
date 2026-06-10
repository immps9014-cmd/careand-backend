<?php

namespace App\Domains\Postpartum\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 산후 챗봇 메시지
 */
class PostpartumChatbotMessage extends Model
{
    use HasFactory;

    protected $table = 'postpartum_chatbot_messages';
    public $timestamps = false;
    protected $dates = ['created_at'];

    protected $fillable = ['session_id', 'role', 'content', 'sources'];

    protected $casts = [
        'sources'    => 'array',
        'created_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PostpartumChatbotSession::class, 'session_id');
    }
}

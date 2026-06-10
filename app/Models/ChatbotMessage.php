<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ChatbotMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'role',
        'content',
        'sources',
        'metadata',
    ];

    protected $casts = [
        'sources' => 'array',
        'metadata' => 'array',
    ];

    public function session()
    {
        return $this->belongsTo(ChatbotSession::class, 'session_id');
    }

}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ChatbotSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'guardian_id',
        'topic',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function guardian()
    {
        return $this->belongsTo(Guardian::class);
    }

    public function messages()
    {
        return $this->hasMany(ChatbotMessage::class, 'session_id');
    }

}
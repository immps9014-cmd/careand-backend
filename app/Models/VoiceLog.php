<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class VoiceLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'audio_url',
        'duration_sec',
        'stt_text',
        'stt_confidence',
        'status',
        'error_message',
    ];

    protected $casts = [
        'stt_confidence' => 'decimal:3',
    ];

    public function session()
    {
        return $this->belongsTo(CareSession::class, 'session_id');
    }

    public function summary()
    {
        return $this->hasOne(AiLogSummary::class, 'voice_log_id');
    }

}
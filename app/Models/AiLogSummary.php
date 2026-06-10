<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AiLogSummary extends Model
{
    use HasFactory;

    protected $table = 'ai_log_summaries';

    protected $fillable = [
        'session_id',
        'voice_log_id',
        'guardian_version',
        'medical_version',
        'categorized',
        'confidence',
        'llm_model',
        'generated_at',
    ];

    protected $casts = [
        'categorized' => 'array',
        'confidence' => 'decimal:3',
        'generated_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(CareSession::class, 'session_id');
    }

    public function voiceLog()
    {
        return $this->belongsTo(VoiceLog::class, 'voice_log_id');
    }

}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CareSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id',
        'actual_start',
        'actual_end',
        'duration_min',
        'status',
        'cancel_reason',
    ];

    protected $casts = [
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
    ];

    public function match()
    {
        return $this->belongsTo(CareMatch::class, 'match_id');
    }

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class, 'session_id');
    }

    public function activities()
    {
        return $this->hasMany(CareActivity::class, 'session_id');
    }

    public function voiceLogs()
    {
        return $this->hasMany(VoiceLog::class, 'session_id');
    }

    public function aiSummaries()
    {
        return $this->hasMany(AiLogSummary::class, 'session_id');
    }

    public function photos()
    {
        return $this->hasMany(CarePhoto::class, 'session_id');
    }

}
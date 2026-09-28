<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CareSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id',
        'scheduled_start',
        'scheduled_end',
        'actual_start',
        'actual_end',
        'duration_min',
        'status',
        'cancel_reason',
        'log_started_at',
        'log_sent_at',
    ];

    protected $casts = [
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
        'log_started_at' => 'datetime',
        'log_sent_at' => 'datetime',
    ];

    /**
     * KPI 「케어일지 작성시간」 시작 시각을 한 번만 기록한다.
     * 케어 종료(퇴근) 전의 기록은 작성 시작으로 치지 않는다 — 계획서 정의가 "케어 종료 후 작성을 시작한 시점"이다.
     */
    public function markLogStarted(): void
    {
        if ($this->status === 'completed' && $this->log_started_at === null) {
            $this->forceFill(['log_started_at' => now()])->save();
        }
    }

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
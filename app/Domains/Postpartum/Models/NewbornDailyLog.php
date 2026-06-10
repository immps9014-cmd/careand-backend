<?php

namespace App\Domains\Postpartum\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 신생아 일일 기록
 *
 * log_type: feeding, diaper, sleep, weight, jaundice, temperature, note
 */
class NewbornDailyLog extends Model
{
    use HasFactory;

    protected $table = 'newborn_daily_logs';

    public $timestamps = false;
    protected $dates = ['log_datetime', 'sleep_start', 'sleep_end', 'created_at'];

    protected $fillable = [
        'newborn_id', 'care_session_id', 'log_datetime', 'log_type',
        'feeding_type', 'feeding_volume_ml', 'feeding_duration_min',
        'diaper_type', 'stool_color',
        'sleep_start', 'sleep_end',
        'weight_g', 'jaundice_level', 'body_temperature',
        'note_text', 'note_audio_url',
        'is_anomaly', 'anomaly_score',
    ];

    protected $casts = [
        'log_datetime'     => 'datetime',
        'sleep_start'      => 'datetime',
        'sleep_end'        => 'datetime',
        'created_at'       => 'datetime',
        'body_temperature' => 'decimal:1',
        'anomaly_score'    => 'decimal:2',
        'is_anomaly'       => 'boolean',
    ];

    // 이상 임계
    public const FEEDING_GAP_HOURS_ALERT  = 8;
    public const NO_DIAPER_HOURS_ALERT    = 24;
    public const SLEEP_MIN_HOURS          = 12;
    public const SLEEP_MAX_HOURS          = 20;
    public const FEVER_TEMP               = 37.5;
    public const JAUNDICE_LEVEL_ALERT     = 3;

    public function newborn(): BelongsTo
    {
        return $this->belongsTo(Newborn::class);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('log_type', $type);
    }

    public function scopeAnomalies($query)
    {
        return $query->where('is_anomaly', true);
    }
}

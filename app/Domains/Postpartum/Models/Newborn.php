<?php

namespace App\Domains\Postpartum\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 신생아
 */
class Newborn extends Model
{
    use HasFactory;

    protected $table = 'newborns';

    protected $fillable = [
        'postpartum_client_id', 'name', 'gender', 'birth_datetime',
        'birth_weight_g', 'birth_height_cm',
        'gestational_age_weeks', 'gestational_age_days', 'birth_order',
        'apgar_1min', 'apgar_5min', 'nicu_days',
        'special_conditions', 'is_alive',
    ];

    protected $casts = [
        'birth_datetime'     => 'datetime',
        'birth_height_cm'    => 'decimal:1',
        'special_conditions' => 'array',
        'is_alive'           => 'boolean',
    ];

    public function postpartumClient(): BelongsTo
    {
        return $this->belongsTo(PostpartumClient::class);
    }

    public function dailyLogs(): HasMany
    {
        return $this->hasMany(NewbornDailyLog::class)->orderByDesc('log_datetime');
    }

    public function anomalyAlerts(): HasMany
    {
        return $this->hasMany(NewbornAnomalyAlert::class)->orderByDesc('detected_at');
    }

    // ===== Helpers =====

    public function ageInDays(): int
    {
        return (int) $this->birth_datetime->diffInDays(now());
    }

    public function ageInWeeks(): int
    {
        return (int) $this->birth_datetime->diffInWeeks(now());
    }

    public function isPreterm(): bool
    {
        return ($this->gestational_age_weeks ?? 40) < 37;
    }

    public function isLowBirthWeight(): bool
    {
        return $this->birth_weight_g < 2500;
    }
}

<?php

namespace App\Domains\Postpartum\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 신생아 발달 이상 알림
 *
 * risk_type: weight_loss, jaundice, feeding_low, temperature, overall
 * severity: low, medium, high, critical
 */
class NewbornAnomalyAlert extends Model
{
    use HasFactory;

    protected $table = 'newborn_anomaly_alerts';
    public $timestamps = false;
    protected $dates = ['detected_at', 'notified_at', 'resolved_at'];

    protected $fillable = [
        'newborn_id', 'detected_at', 'risk_type', 'severity',
        'score', 'triggers', 'recommendations',
        'notified_at', 'resolved_at', 'resolved_by', 'resolution_note',
    ];

    protected $casts = [
        'detected_at'     => 'datetime',
        'notified_at'     => 'datetime',
        'resolved_at'     => 'datetime',
        'score'           => 'decimal:2',
        'triggers'        => 'array',
        'recommendations' => 'array',
    ];

    public function newborn(): BelongsTo
    {
        return $this->belongsTo(Newborn::class);
    }

    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeCriticalUnresolved($query)
    {
        return $query->where('severity', 'critical')->whereNull('resolved_at');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function markResolved(int $userId, string $note): void
    {
        $this->update([
            'resolved_at'     => now(),
            'resolved_by'     => $userId,
            'resolution_note' => $note,
        ]);
    }
}

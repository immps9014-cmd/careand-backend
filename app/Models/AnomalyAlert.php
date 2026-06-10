<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AnomalyAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'senior_id',
        'risk_type',
        'risk_score',
        'severity',
        'trigger_pattern',
        'recommendation',
        'status',
        'resolution_note',
        'resolved_by',
        'detected_at',
        'resolved_at',
    ];

    protected $casts = [
        'risk_score' => 'decimal:2',
        'trigger_pattern' => 'array',
        'recommendation' => 'array',
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function senior()
    {
        return $this->belongsTo(Senior::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }


    public function scopeUnresolved($query)
    {
        return $query->whereIn('status', ['new', 'acknowledged', 'in_progress']);
    }

    public function scopeHigh($query)
    {
        return $query->whereIn('severity', ['high', 'critical']);
    }

}
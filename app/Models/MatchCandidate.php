<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MatchCandidate extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'caregiver_id',
        'source',
        'ai_score',
        'ai_reasons',
        'rank',
        'response',
        'responded_at',
        'bid_hourly',
        'bid_note',
        'bid_status',
        'bid_at',
    ];

    protected $casts = [
        'ai_score' => 'decimal:3',
        'ai_reasons' => 'array',
        'responded_at' => 'datetime',
        'bid_hourly' => 'decimal:2',
        'bid_at' => 'datetime',
    ];

    public function request()
    {
        return $this->belongsTo(MatchRequest::class, 'request_id');
    }

    public function caregiver()
    {
        return $this->belongsTo(Caregiver::class);
    }

}
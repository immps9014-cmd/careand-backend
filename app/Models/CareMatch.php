<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CareMatch extends Model
{
    use HasFactory;

    protected $table = 'matches';

    protected $fillable = [
        'request_id',
        'caregiver_id',
        'scheduled_start',
        'scheduled_end',
        'hourly_rate',
        'estimated_amount',
        'status',
    ];

    protected $casts = [
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'hourly_rate' => 'decimal:2',
        'estimated_amount' => 'decimal:2',
    ];

    public function request()
    {
        return $this->belongsTo(MatchRequest::class, 'request_id');
    }

    public function caregiver()
    {
        return $this->belongsTo(Caregiver::class);
    }

    public function careSessions()
    {
        return $this->hasMany(CareSession::class, 'match_id');
    }

    public function payment()
    {
        return $this->hasOne(Payment::class, 'match_id');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class, 'match_id');
    }

}
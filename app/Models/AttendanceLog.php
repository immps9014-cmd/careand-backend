<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AttendanceLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'event_type',
        'lat',
        'lng',
        'distance_m',
        'accuracy_m',
        'is_valid',
        'logged_at',
    ];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'distance_m' => 'decimal:2',
        'is_valid' => 'boolean',
        'logged_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(CareSession::class, 'session_id');
    }

}
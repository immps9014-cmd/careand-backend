<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class VitalRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'senior_id',
        'session_id',
        'blood_pressure_sys',
        'blood_pressure_dia',
        'blood_sugar',
        'body_temperature',
        'heart_rate',
        'weight',
        'measured_at',
    ];

    protected $casts = [
        'body_temperature' => 'decimal:1',
        'weight' => 'decimal:2',
        'measured_at' => 'datetime',
    ];

    public function senior()
    {
        return $this->belongsTo(Senior::class);
    }

    public function session()
    {
        return $this->belongsTo(CareSession::class, 'session_id');
    }

}
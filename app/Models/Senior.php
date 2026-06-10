<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\SoftDeletes;

class Senior extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'guardian_id',
        'user_id',
        'name',
        'birth_date',
        'gender',
        'care_grade',
        'care_grade_no',
        'diseases',
        'special_notes',
        'home_address',
        'home_lat',
        'home_lng',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'diseases' => 'array',
        'home_lat' => 'decimal:7',
        'home_lng' => 'decimal:7',
    ];

    public function guardian()
    {
        return $this->belongsTo(Guardian::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function matchRequests()
    {
        return $this->hasMany(MatchRequest::class);
    }

    public function vitalRecords()
    {
        return $this->hasMany(VitalRecord::class);
    }

    public function anomalyAlerts()
    {
        return $this->hasMany(AnomalyAlert::class);
    }

    public function ltcVouchers()
    {
        return $this->hasMany(LtcVoucher::class);
    }


    public function getAgeAttribute(): int
    {
        return $this->birth_date->age;
    }

}
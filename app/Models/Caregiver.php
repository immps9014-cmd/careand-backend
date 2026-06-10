<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\SoftDeletes;

class Caregiver extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'org_id',
        'birth_date',
        'gender',
        'license_no',
        'license_issued_at',
        'license_verified_at',
        'specialties',
        'base_address',
        'base_lat',
        'base_lng',
        'rating_avg',
        'rating_count',
        'completed_sessions',
        'grade_level',
        'status',
        'rejection_reason',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'license_issued_at' => 'date',
        'license_verified_at' => 'datetime',
        'specialties' => 'array',
        'base_lat' => 'decimal:7',
        'base_lng' => 'decimal:7',
        'rating_avg' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function matchCandidates()
    {
        return $this->hasMany(MatchCandidate::class);
    }

    public function matches()
    {
        return $this->hasMany(CareMatch::class);
    }

    public function settlements()
    {
        return $this->hasMany(Settlement::class);
    }


    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function isLicenseVerified(): bool
    {
        return $this->license_verified_at !== null;
    }

}
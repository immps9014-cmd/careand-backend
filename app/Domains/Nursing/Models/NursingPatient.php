<?php

namespace App\Domains\Nursing\Models;

use App\Models\Guardian;
use App\Models\MatchRequest;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NursingPatient extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'guardian_id',
        'name',
        'birth_date',
        'gender',
        'hospital_name',
        'hospital_address',
        'hospital_lat',
        'hospital_lng',
        'ward_room',
        'mobility',
        'diseases',
        'care_requirements',
        'special_notes',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'diseases' => 'array',
        'care_requirements' => 'array',
    ];

    public function guardian()
    {
        return $this->belongsTo(Guardian::class);
    }

    public function matchRequests()
    {
        return $this->hasMany(MatchRequest::class, 'nursing_patient_id');
    }
}

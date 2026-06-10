<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'biz_no',
        'name',
        'representative',
        'address',
        'contact_phone',
        'biz_type',
        'certifications',
        'status',
    ];

    protected $casts = [
        'certifications' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function caregivers()
    {
        return $this->hasMany(Caregiver::class, 'org_id');
    }

}
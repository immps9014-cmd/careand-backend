<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaregiverInvite extends Model
{
    protected $fillable = [
        'org_id',
        'phone',
        'token',
        'status',
        'invited_by_user_id',
        'accepted_caregiver_id',
        'accepted_at',
        'expires_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}

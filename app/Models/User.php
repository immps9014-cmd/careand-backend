<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'email',
        'phone',
        'name',
        'role',
        'password',
        'fcm_token',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'password' => 'hashed',
    ];


    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'role' => $this->role,
            'name' => $this->name,
        ];
    }

    public function guardian()
    {
        return $this->hasOne(Guardian::class);
    }

    public function caregiver()
    {
        return $this->hasOne(Caregiver::class);
    }

    public function organization()
    {
        return $this->hasOne(Organization::class);
    }

    public function admin()
    {
        return $this->hasOne(Admin::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function isGuardian(): bool { return $this->role === 'guardian'; }
    public function isCaregiver(): bool { return $this->role === 'caregiver'; }
    public function isAdmin(): bool { return $this->role === 'admin'; }

}
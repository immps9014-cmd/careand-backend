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

    /**
     * Phase 2 산후 정책의 RBAC 14역할명 호환 shim.
     * Spatie Permission 미도입 — 단일 role 컬럼(admin/guardian/caregiver)으로 매핑한다.
     * (RBAC 정식 도입 시 이 shim과 정책의 역할명을 함께 마이그레이션할 것)
     */
    private const LEGACY_ROLE_MAP = [
        'super_admin' => 'admin',
        'hq_operator' => 'admin',
        'branch_manager' => 'admin',
        'franchisee' => 'admin',
        'postpartum_client' => 'guardian',
        'family_postpartum' => 'guardian',
        'caregiver_postpartum' => 'caregiver',
        'caregiver_multi' => 'caregiver',
    ];

    public function hasRole(string $role): bool
    {
        return $this->role === (self::LEGACY_ROLE_MAP[$role] ?? $role);
    }

    public function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

}
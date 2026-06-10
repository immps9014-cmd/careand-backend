<?php

namespace App\Domains\Shared\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Branch — 지점 마스터 (직영 4 + 가맹)
 */
class Branch extends Model
{
    use HasFactory;

    protected $table = 'branches';

    protected $fillable = [
        'code', 'name', 'type', 'parent_branch_id',
        'address', 'address_detail', 'region_code',
        'phone', 'manager_user_id', 'business_number',
        'opened_at', 'closed_at', 'status',
    ];

    protected $casts = [
        'opened_at' => 'date',
        'closed_at' => 'date',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'parent_branch_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Branch::class, 'parent_branch_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDirect($query)
    {
        return $query->where('type', 'direct');
    }

    public function scopeFranchise($query)
    {
        return $query->where('type', 'franchise');
    }

    public function isFranchise(): bool
    {
        return $this->type === 'franchise';
    }
}

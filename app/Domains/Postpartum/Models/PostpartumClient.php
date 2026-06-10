<?php

namespace App\Domains\Postpartum\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;
use App\Domains\Shared\Models\Branch;

/**
 * 산모 클라이언트
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property \Carbon\Carbon $delivery_date
 * @property string $delivery_type natural|cesarean|vbac
 * @property bool $is_first_baby
 * @property bool $is_multiple_birth
 * @property string $voucher_grade a_type|b_type|c_type|d_type|e_type
 * @property float $voucher_self_pay_rate
 * @property int $voucher_total_days
 * @property int $voucher_used_days
 * @property string $status active|completed|cancelled
 */
class PostpartumClient extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'postpartum_clients';

    protected $fillable = [
        'user_id', 'name', 'name_encrypted', 'phone_encrypted',
        'birth_date', 'address', 'address_detail', 'region_code', 'branch_id',
        'delivery_date', 'delivery_type', 'is_first_baby', 'is_multiple_birth',
        'breastfeeding_intent',
        'pregnancy_complications', 'postpartum_conditions', 'medications',
        'voucher_grade', 'voucher_self_pay_rate',
        'voucher_total_days', 'voucher_used_days',
        'voucher_amount_total', 'voucher_amount_used', 'voucher_certified_at',
        'status', 'special_notes',
    ];

    protected $casts = [
        'birth_date'              => 'date',
        'delivery_date'           => 'date',
        'is_first_baby'           => 'boolean',
        'is_multiple_birth'       => 'boolean',
        'pregnancy_complications' => 'array',
        'postpartum_conditions'   => 'array',
        'medications'             => 'array',
        'voucher_self_pay_rate'   => 'decimal:4',
        'voucher_amount_total'    => 'decimal:2',
        'voucher_amount_used'     => 'decimal:2',
        'voucher_certified_at'    => 'date',
    ];

    protected $hidden = ['name_encrypted', 'phone_encrypted'];

    // ===== Relationships =====

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function newborns(): HasMany
    {
        return $this->hasMany(Newborn::class);
    }

    public function epdsAssessments(): HasMany
    {
        return $this->hasMany(EpdsAssessment::class)->orderByDesc('assessment_date');
    }

    public function voucherTransactions(): HasMany
    {
        return $this->hasMany(VoucherTransaction::class)->orderByDesc('transaction_date');
    }

    public function chatbotSessions(): HasMany
    {
        return $this->hasMany(PostpartumChatbotSession::class);
    }

    // ===== Scopes =====

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    // ===== Helpers =====

    public function voucherRemainingDays(): int
    {
        return max(0, ($this->voucher_total_days ?? 0) - $this->voucher_used_days);
    }

    public function voucherRemainingAmount(): float
    {
        return max(0, ($this->voucher_amount_total ?? 0) - $this->voucher_amount_used);
    }

    public function isVoucherEligible(): bool
    {
        return $this->voucher_grade !== null
            && $this->voucher_certified_at !== null
            && $this->voucherRemainingDays() > 0;
    }

    public function daysSinceDelivery(): int
    {
        return (int) $this->delivery_date->diffInDays(now());
    }
}

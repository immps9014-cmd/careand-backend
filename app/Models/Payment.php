<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'guardian_id',
        'match_id',
        'total_amount',
        'amount_self_pay',
        'amount_ltc_pay',
        'method',
        'pg_provider',
        'pg_tid',
        'idempotency_key',
        'status',
        'pg_response',
        'paid_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'amount_self_pay' => 'decimal:2',
        'amount_ltc_pay' => 'decimal:2',
        'pg_response' => 'array',
        'paid_at' => 'datetime',
    ];

    public function guardian()
    {
        return $this->belongsTo(Guardian::class);
    }

    public function match()
    {
        return $this->belongsTo(CareMatch::class, 'match_id');
    }

    public function items()
    {
        return $this->hasMany(PaymentItem::class);
    }

}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LtcVoucher extends Model
{
    use HasFactory;

    protected $fillable = [
        'senior_id',
        'period_month',
        'monthly_limit',
        'used_amount',
        'remaining_amount',
        'copay_rate',
    ];

    protected $casts = [
        'period_month' => 'date',
        'monthly_limit' => 'decimal:2',
        'used_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    public function senior()
    {
        return $this->belongsTo(Senior::class);
    }

}
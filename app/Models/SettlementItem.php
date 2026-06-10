<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SettlementItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'settlement_id',
        'session_id',
        'hours',
        'hourly_rate',
        'amount',
        'surcharge',
    ];

    protected $casts = [
        'hours' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'surcharge' => 'decimal:2',
    ];

    public function settlement()
    {
        return $this->belongsTo(Settlement::class);
    }

    public function session()
    {
        return $this->belongsTo(CareSession::class, 'session_id');
    }

}
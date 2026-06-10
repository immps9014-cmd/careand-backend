<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Settlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'caregiver_id',
        'period_start',
        'period_end',
        'gross_amount',
        'withholding_tax_3_3',
        'net_amount',
        'hometax_filing_no',
        'bank_tx_id',
        'status',
        'confirmed_by',
        'confirmed_at',
        'paid_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'gross_amount' => 'decimal:2',
        'withholding_tax_3_3' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'confirmed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function caregiver()
    {
        return $this->belongsTo(Caregiver::class);
    }

    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function items()
    {
        return $this->hasMany(SettlementItem::class);
    }

}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 산모신생아 바우처 기간형 계약(제공기관 = 케어앤) */
class MnhContract extends Model
{
    protected $fillable = [
        'contract_no', 'postpartum_client_id', 'user_id', 'support_type_id', 'year',
        'fetus_type', 'birth_order', 'income_tier', 'period', 'days',
        'total_price', 'gov_support', 'self_pay', 'start_date', 'weekdays', 'skip_dates',
        'daily_start', 'daily_minutes', 'payment_method', 'prepaid_amount', 'prepaid_at',
        'prepaid_receipt_no', 'prepaid_by', 'status', 'match_request_id', 'caregiver_id',
        'member_note', 'admin_note', 'cancel_reason', 'holiday_work_dates', 'refund_amount', 'refunded_at',
        'addons', 'addon_total', 'provisional', 'start_change_request',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'weekdays' => 'array',
        'skip_dates' => 'array',
        'holiday_work_dates' => 'array',
        'addons' => 'array',
        'addon_total' => 'integer',
        'provisional' => 'boolean',
        'start_change_request' => 'array',
        'prepaid_at' => 'datetime',
        'refunded_at' => 'datetime',
        'refund_amount' => 'integer',
        'days' => 'integer', 'daily_minutes' => 'integer',
        'total_price' => 'integer', 'gov_support' => 'integer', 'self_pay' => 'integer', 'prepaid_amount' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(\App\Domains\Postpartum\Models\PostpartumClient::class, 'postpartum_client_id');
    }

    public function events()
    {
        return $this->hasMany(MnhContractEvent::class, 'contract_id');
    }
}

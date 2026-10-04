<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 산모신생아 바우처 지원유형 기준표(복지부 연도별 고시값, 운영자 입력) */
class MnhSupportType extends Model
{
    protected $fillable = [
        'year', 'fetus_type', 'birth_order', 'income_tier', 'period', 'days',
        'total_price', 'gov_support', 'self_pay', 'note', 'is_active',
    ];

    protected $casts = [
        'year' => 'integer', 'days' => 'integer', 'total_price' => 'integer',
        'gov_support' => 'integer', 'self_pay' => 'integer', 'is_active' => 'boolean',
    ];
}

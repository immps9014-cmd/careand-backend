<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 기준중위소득 150% 판정 — 가구원수(태아 포함)별 건보료 본인부담 상한(연도별 고시값) */
class MnhIncomeCriterion extends Model
{
    protected $table = 'mnh_income_criteria';

    protected $fillable = ['year', 'household_size', 'income_limit', 'premium_employee', 'premium_regional', 'premium_mixed'];

    protected $casts = [
        'year' => 'integer', 'household_size' => 'integer', 'income_limit' => 'integer',
        'premium_employee' => 'integer', 'premium_regional' => 'integer', 'premium_mixed' => 'integer',
    ];
}

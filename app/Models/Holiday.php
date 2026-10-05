<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 관공서 공휴일(대체공휴일·선거일·임시공휴일 포함). 바우처 제공일 계산이 쓴다. */
class Holiday extends Model
{
    protected $fillable = ['date', 'name', 'source'];

    protected $casts = ['date' => 'date:Y-m-d'];
}

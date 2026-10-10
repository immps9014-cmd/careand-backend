<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 케어앤 자체 추가요금·대여용품(바우처 밖, 제공기관 가격) */
class MnhAddonItem extends Model
{
    protected $fillable = ['kind', 'name', 'unit_label', 'price', 'max_qty', 'note', 'sort', 'is_active'];

    protected $casts = ['price' => 'integer', 'max_qty' => 'integer', 'sort' => 'integer', 'is_active' => 'boolean'];
}

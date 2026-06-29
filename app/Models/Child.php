<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 아이돌봄(childcare) 대상 아동. 보호자(guardian) 소유.
 * seniors와 동일하게 home_lat/home_lng를 보유 → MatchRequest의 generic 거리 매칭(default arm) 재사용.
 */
class Child extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'guardian_id',
        'name',
        'birth_date',
        'gender',
        'home_address',
        'home_lat',
        'home_lng',
        'special_notes',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'home_lat' => 'decimal:7',
        'home_lng' => 'decimal:7',
    ];

    public function guardian()
    {
        return $this->belongsTo(Guardian::class);
    }
}

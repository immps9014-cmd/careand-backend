<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 마음돌봄(mental_care) 대상자. 보호자(guardian) 소유 — 본인/가족.
 * children과 동일하게 home_lat/home_lng 보유 → MatchRequest generic 거리 매칭(default arm) 재사용.
 */
class MentalCareClient extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'guardian_id',
        'name',
        'birth_date',
        'gender',
        'relation',
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

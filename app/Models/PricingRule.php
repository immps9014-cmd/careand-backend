<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PricingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'region_code',
        'region_index',
        'night_mult',
        'holiday_mult',
        'emergency_mult',
        'acuity_addons',
        'min_hourly',
        'is_active',
    ];

    protected $casts = [
        'region_index' => 'decimal:2',
        'night_mult' => 'decimal:2',
        'holiday_mult' => 'decimal:2',
        'emergency_mult' => 'decimal:2',
        'acuity_addons' => 'array',
        'min_hourly' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    /**
     * 카테고리(+지역)에 맞는 활성 룰을 해석한다.
     * 지역 전용 룰이 없으면 전국 기본(region_code=null)으로 폴백, 그것도 없으면 합성 기본값.
     */
    public static function resolve(int $categoryId, ?string $regionCode = null): self
    {
        $query = static::where('category_id', $categoryId)->where('is_active', true);

        if ($regionCode) {
            $rule = (clone $query)->where('region_code', $regionCode)->first();
            if ($rule) {
                return $rule;
            }
        }

        $national = (clone $query)->whereNull('region_code')->first();
        if ($national) {
            return $national;
        }

        // 룰 미시드 상태 안전 폴백 (산출이 절대 깨지지 않도록)
        return new self([
            'category_id' => $categoryId,
            'region_code' => null,
            'region_index' => 1.00,
            'night_mult' => 1.30,
            'holiday_mult' => 1.50,
            'emergency_mult' => 1.20,
            'acuity_addons' => [],
            'min_hourly' => (float) config('services.pricing.min_hourly', 10030),
            'is_active' => true,
        ]);
    }
}

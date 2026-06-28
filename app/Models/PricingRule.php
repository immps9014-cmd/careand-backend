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

    /**
     * 표준 시/도 키 → 지역 물가 계수(region_index). 전국 기본 = 1.00.
     * 주소 첫 토큰을 이 키로 정규화해 매칭하므로, 시드와 해석이 같은 진실원을 공유한다.
     * 계수는 생활물가·간병 인건비 시장 수준을 반영한 근사값(추후 실거래 기반 보정 가능).
     */
    public const SIDO_INDEX = [
        '서울' => 1.15,
        '경기' => 1.08,
        '인천' => 1.05,
        '세종' => 1.05,
        '울산' => 1.03,
        '부산' => 1.02,
        '대구' => 1.00,
        '대전' => 1.00,
        '광주' => 1.00,
        '제주' => 1.00,
        '충남' => 0.97,
        '경남' => 0.97,
        '강원' => 0.96,
        '충북' => 0.96,
        '경북' => 0.95,
        '전북' => 0.94,
        '전남' => 0.93,
    ];

    /**
     * 주소 첫 토큰(서울/서울시/서울특별시, 경기/경기도, 강원특별자치도 …)을
     * 표준 2자 시/도 키로 정규화한다. 17개 시/도 어느 것으로도 시작하지 않으면 null(전국 기본).
     */
    public static function canonicalSido(?string $token): ?string
    {
        if ($token === null) {
            return null;
        }
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        foreach (array_keys(self::SIDO_INDEX) as $sido) {
            if (str_starts_with($token, $sido)) {
                return $sido;
            }
        }
        return null;
    }

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

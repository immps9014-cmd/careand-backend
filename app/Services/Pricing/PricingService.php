<?php

namespace App\Services\Pricing;

use App\Models\MatchRequest;
use App\Models\PricingRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 적정 간병비 산출 (Fair Price Estimator).
 *
 * 결정론 룰값(카테고리 기준시급 × 지역계수 × 시간대/긴급 배수 + 난이도 가산)에
 * 과거 합의시급(matches.hourly_rate) 백분위를 베이지안식으로 블렌딩해
 * 권장 가격대 [floor, suggested, ceil] 를 산출한다.
 *
 * 저장된 요청과 미저장(미리보기) MatchRequest 인스턴스 모두 처리한다.
 * 외부 의존 없이 DB만 사용 — AI 박스 egress 제약을 회피하기 위해 백엔드에 둔다.
 */
class PricingService
{
    private const TZ = 'Asia/Seoul';

    /** 과거 합의시급 표본이 이 건수에 도달하면 블렌딩 비중이 최대(0.5)가 된다(선형 램프). */
    private const BLEND_FULL_AT = 40;

    /** 블렌딩 최대 비중 — 표본이 많아도 룰값 절반은 항상 유지(이상치·과적합 방지). */
    private const BLEND_MAX = 0.5;

    public function estimate(MatchRequest $req): array
    {
        $category = $req->category;
        $base = $category ? (float) $category->base_rate : 0.0;

        $regionCode = $this->regionCodeOf($req);
        $rule = PricingRule::resolve((int) $req->category_id, $regionCode);

        $regionIndex = (float) $rule->region_index;
        $nightMult = $this->isNight($req) ? (float) $rule->night_mult : 1.0;
        $holidayMult = $this->isHoliday($req) ? (float) $rule->holiday_mult : 1.0;
        $emergencyMult = $req->mode === 'emergency' ? (float) $rule->emergency_mult : 1.0;
        $acuityAddon = $this->acuityAddon($req, $rule);

        $ruleSuggested = $this->round100(
            $base * $regionIndex * $nightMult * $holidayMult * $emergencyMult + $acuityAddon
        );

        [$p25, $p50, $p75, $n] = $this->history((int) $req->category_id);
        // 0건→0, BLEND_FULL_AT(40)건→BLEND_MAX(0.5)로 선형 램프 후 포화.
        // 표본 적으면 룰 우세, 충분해도 시장 반영은 최대 50%.
        $blend = min($n / self::BLEND_FULL_AT * self::BLEND_MAX, self::BLEND_MAX);

        $suggested = $n > 0
            ? $this->round100($ruleSuggested * (1 - $blend) + $p50 * $blend)
            : $ruleSuggested;

        $minHourly = (float) $rule->min_hourly;
        $floor = max($minHourly, $n > 0 ? (float) $p25 : $this->round100($ruleSuggested * 0.8));
        $ceil = max($this->round100($ruleSuggested * 1.4), $n > 0 ? (float) $p75 : 0);

        // 정합 보정: floor ≤ suggested ≤ ceil 보장
        $suggested = max($floor, min($suggested, $ceil));

        return [
            'floor' => (float) $floor,
            'suggested' => (float) $suggested,
            'ceil' => (float) $ceil,
            'n_samples' => $n,
            'inputs' => [
                'base_rate' => $base,
                'region_code' => $regionCode,
                'min_hourly' => $minHourly,
                'is_night' => $nightMult > 1.0,
                'is_holiday' => $holidayMult > 1.0,
                'is_emergency' => $emergencyMult > 1.0,
            ],
            'breakdown' => [
                'region_index' => $regionIndex,
                'night_mult' => $nightMult,
                'holiday_mult' => $holidayMult,
                'emergency_mult' => $emergencyMult,
                'acuity_addon' => $acuityAddon,
                'rule_suggested' => $ruleSuggested,
                'blend' => round($blend, 3),
            ],
        ];
    }

    /** 대상자 주소에서 시/도 토큰을 추출(지역 룰 매칭용). 미상이면 null=전국 기본. */
    private function regionCodeOf(MatchRequest $req): ?string
    {
        $recipient = $req->recipient();
        if (!$recipient) {
            return null;
        }
        $address = $recipient->home_address
            ?? $recipient->address
            ?? $recipient->hospital_address
            ?? null;
        if (!$address) {
            return null;
        }
        $first = preg_split('/\s+/', trim($address))[0] ?? null;
        return $first ?: null;
    }

    private function localStart(MatchRequest $req): ?Carbon
    {
        if (!$req->scheduled_start) {
            return null;
        }
        return Carbon::parse($req->scheduled_start)->setTimezone(self::TZ);
    }

    /** 시작~종료 구간이 야간(22:00~06:00)과 겹치면 야간으로 본다. */
    private function isNight(MatchRequest $req): bool
    {
        $start = $this->localStart($req);
        if (!$start) {
            return false;
        }
        $duration = (int) ($req->duration_min ?? 0);
        $cursor = $start->copy();
        $end = $start->copy()->addMinutes(max($duration, 1));
        // 시간 단위로 스캔하여 야간대 포함 여부 판단(최대 24시간 캡)
        for ($i = 0; $i < 24 && $cursor->lt($end); $i++) {
            $h = (int) $cursor->format('G');
            if ($h >= 22 || $h < 6) {
                return true;
            }
            $cursor->addHour();
        }
        return false;
    }

    /** 일요일 또는 설정된 공휴일 목록에 해당하면 공휴일로 본다. */
    private function isHoliday(MatchRequest $req): bool
    {
        $start = $this->localStart($req);
        if (!$start) {
            return false;
        }
        if ($start->isSunday()) {
            return true;
        }
        $holidays = (array) config('services.pricing.holidays', []);
        return in_array($start->format('Y-m-d'), $holidays, true);
    }

    /** 대상자 질환/요구사항에 매칭되는 난이도 가산 합. */
    private function acuityAddon(MatchRequest $req, PricingRule $rule): float
    {
        $addons = (array) ($rule->acuity_addons ?? []);
        if (!$addons) {
            return 0.0;
        }

        $tags = [];
        $features = $req->recipientFeatures();
        if ($features && !empty($features['diseases']) && is_array($features['diseases'])) {
            $tags = array_merge($tags, $features['diseases']);
        }
        $requirements = (array) ($req->requirements ?? []);
        foreach ($requirements as $value) {
            if (is_string($value)) {
                $tags[] = $value;
            }
        }

        $sum = 0.0;
        foreach ($addons as $tag => $amount) {
            if (in_array($tag, $tags, true)) {
                $sum += (float) $amount;
            }
        }
        return $sum;
    }

    /** 동일 카테고리의 최근 합의시급 백분위(p25,p50,p75)와 표본수. */
    private function history(int $categoryId): array
    {
        $rates = DB::table('matches')
            ->join('match_requests', 'match_requests.id', '=', 'matches.request_id')
            ->where('match_requests.category_id', $categoryId)
            ->whereIn('matches.status', ['confirmed', 'in_progress', 'completed'])
            ->orderByDesc('matches.id')
            ->limit(500)
            ->pluck('matches.hourly_rate')
            ->map(fn ($r) => (float) $r)
            ->filter(fn ($r) => $r > 0)
            ->values()
            ->all();

        $n = count($rates);
        if ($n === 0) {
            return [0.0, 0.0, 0.0, 0];
        }
        sort($rates);
        return [
            $this->percentile($rates, 0.25),
            $this->percentile($rates, 0.50),
            $this->percentile($rates, 0.75),
            $n,
        ];
    }

    /** @param array<int,float> $sorted 오름차순 정렬된 값 */
    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return $sorted[0];
        }
        $rank = $p * ($n - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return $sorted[$low];
        }
        $frac = $rank - $low;
        return $sorted[$low] * (1 - $frac) + $sorted[$high] * $frac;
    }

    private function round100(float $value): float
    {
        return round($value / 100) * 100;
    }
}

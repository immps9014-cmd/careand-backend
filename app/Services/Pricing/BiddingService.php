<?php

namespace App\Services\Pricing;

use App\Models\Caregiver;
use App\Models\MatchCandidate;
use App\Models\MatchRequest;
use Illuminate\Support\Carbon;

/**
 * 역경매 입찰 도메인 로직 — 자동입찰과 입찰 가드레일.
 */
class BiddingService
{
    /**
     * 초대된 후보 중 auto_bid 설정 돌봄전문가에 대해 default_rate로 자동 입찰.
     * 권장 가격대가 있으면 [floor, ceil]로 클램프. 후보 생성 직후 호출.
     */
    public function applyAutoBids(MatchRequest $request): void
    {
        $estimate = $request->price_estimate;

        $candidates = MatchCandidate::where('request_id', $request->id)
            ->where('bid_status', 'invited')
            ->whereNull('bid_hourly')
            ->get();

        if ($candidates->isEmpty()) {
            return;
        }

        $caregivers = Caregiver::whereIn('id', $candidates->pluck('caregiver_id'))
            ->where('auto_bid', true)
            ->whereNotNull('default_rate')
            ->get()
            ->keyBy('id');

        foreach ($candidates as $candidate) {
            $caregiver = $caregivers->get($candidate->caregiver_id);
            if (!$caregiver) {
                continue;
            }
            $candidate->update([
                'bid_hourly' => $this->clampToBand((float) $caregiver->default_rate, $estimate),
                'bid_note' => '자동 입찰',
                'bid_status' => 'bid',
                'bid_at' => Carbon::now(),
            ]);
        }
    }

    /** 입찰가를 권장 가격대 [floor, ceil] 안으로 클램프. 밴드 없으면 그대로. */
    public function clampToBand(float $rate, ?array $estimate): float
    {
        if (!$estimate) {
            return $rate;
        }
        $floor = (float) ($estimate['floor'] ?? 0);
        $ceil = (float) ($estimate['ceil'] ?? 0);
        if ($floor > 0) {
            $rate = max($rate, $floor);
        }
        if ($ceil > 0) {
            $rate = min($rate, $ceil);
        }
        return $rate;
    }

    /** 권장 가격대 밖이면 true(소프트 경고용). 밴드 없으면 false. */
    public function isOutOfBand(float $rate, ?array $estimate): bool
    {
        if (!$estimate) {
            return false;
        }
        $floor = (float) ($estimate['floor'] ?? 0);
        $ceil = (float) ($estimate['ceil'] ?? 0);
        return ($floor > 0 && $rate < $floor) || ($ceil > 0 && $rate > $ceil);
    }

    /** 하드 하한(법정 최저시급). 스냅샷 우선, 없으면 설정값. */
    public function minHourly(?array $estimate): float
    {
        return (float) ($estimate['inputs']['min_hourly']
            ?? config('services.pricing.min_hourly', 10030));
    }
}

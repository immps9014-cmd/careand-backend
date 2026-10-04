<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * 매칭 결제 완료 여부 — 토스 결제(payments.paid) 또는 바우처 계약 본인부담금 선납(mnh_contracts.prepaid_at).
 * 바우처 계약은 제공기관(케어앤)에 현금·카드·지역화폐로 먼저 내므로 앱 결제창을 거치지 않는다(2026-10-05).
 */
final class MatchPaid
{
    public static function is(int $matchId): bool
    {
        return isset(self::ids([$matchId])[$matchId]);
    }

    /** @return array<int, true> 결제 완료로 볼 match_id 집합 */
    public static function ids(iterable $matchIds): array
    {
        $ids = collect($matchIds)->map(fn ($i) => (int) $i)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $paid = DB::table('payments')->whereIn('match_id', $ids)->where('status', 'paid')->pluck('match_id');
        $prepaid = DB::table('matches as m')->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->join('mnh_contracts as c', 'c.id', '=', 'r.mnh_contract_id')
            ->whereIn('m.id', $ids)->whereNotNull('c.prepaid_at')->pluck('m.id');

        return $paid->merge($prepaid)->mapWithKeys(fn ($i) => [(int) $i => true])->all();
    }

    /** 바우처 계약 매칭인지(앱 결제창 대상 아님) */
    public static function isVoucherContract(int $matchId): bool
    {
        return DB::table('matches as m')->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->where('m.id', $matchId)->whereNotNull('r.mnh_contract_id')->exists();
    }

    /**
     * 화면용 결제 상태 — 바우처 계약 매칭은 선납이면 'paid', 아니면 'voucher'(앱 결제 대상 아님 → 「결제하기」 숨김).
     * 그 밖엔 payments.status 그대로(없으면 null).
     */
    public static function displayStatus(int $matchId, ?string $paymentStatus): ?string
    {
        $prepaid = DB::table('matches as m')->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->join('mnh_contracts as c', 'c.id', '=', 'r.mnh_contract_id')
            ->where('m.id', $matchId)->value(DB::raw('c.prepaid_at IS NOT NULL'));
        if ($prepaid === null) {
            return $paymentStatus;
        }

        return (int) $prepaid === 1 ? 'paid' : 'voucher';
    }
}

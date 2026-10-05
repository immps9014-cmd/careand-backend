<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 산모신생아 바우처 선납 매출(2026-10-05). 본인부담금은 토스 결제(payments)가 아니라 관리자가 기록하는
 * 선납(mnh_contracts.prepaid_*)이라 결제 집계에 빠져 있었다 — 매출 화면은 결제 매출에 이것을 더한다.
 * - 선납액: prepaid_at 시점 +prepaid_amount, 계약 취소 환불: refunded_at 시점 −refund_amount.
 * - 정부지원금(바우처 결제분)은 기관이 정부에서 받는 돈이라 여기 넣지 않는다.
 * - 도메인은 postpartum(산모신생아), 지점은 담당 관리사의 지점, 지역은 산모 주소 앞 2어절.
 * 결제 기록(payments)을 만들지 않는 이유: 토스 취소·회원 결제 내역·출근 차단 판정이 payments 를 보기 때문.
 */
class VoucherRevenue
{
    public const DOMAIN = 'postpartum';

    /**
     * 기간 안의 매출 항목 [{kind: prepaid|refund, amount(부호 포함), at, contract_id, branch_id, region}]
     * $from 이상 $to 미만(UTC). $branch 를 주면 그 지점 담당 계약만.
     */
    public static function entries(CarbonInterface $from, CarbonInterface $to, ?int $branch = null): Collection
    {
        $base = fn () => DB::table('mnh_contracts as c')
            ->leftJoin('caregivers as cg', 'cg.id', '=', 'c.caregiver_id')
            ->leftJoin('postpartum_clients as pc', 'pc.id', '=', 'c.postpartum_client_id')
            ->when($branch, fn ($q) => $q->where('cg.branch_id', $branch))
            ->select('c.id as contract_id', 'cg.branch_id', DB::raw("SUBSTRING_INDEX(COALESCE(NULLIF(pc.address,''), '미지정'), ' ', 2) as region"));

        $paid = $base()->whereNotNull('c.prepaid_at')->where('c.prepaid_amount', '>', 0)
            ->where('c.prepaid_at', '>=', $from)->where('c.prepaid_at', '<', $to)
            ->addSelect(DB::raw("'prepaid' as kind"), 'c.prepaid_amount as amount', 'c.prepaid_at as at')->get();
        $refund = $base()->whereNotNull('c.refunded_at')->where('c.refund_amount', '>', 0)
            ->where('c.refunded_at', '>=', $from)->where('c.refunded_at', '<', $to)
            ->addSelect(DB::raw("'refund' as kind"), DB::raw('-1 * c.refund_amount as amount'), 'c.refunded_at as at')->get();

        return $paid->concat($refund)->map(function ($r) {
            $r->amount = (int) $r->amount;

            return $r;
        })->values();
    }

    /** 순매출(선납 − 환불) */
    public static function net(CarbonInterface $from, CarbonInterface $to, ?int $branch = null): int
    {
        return (int) self::entries($from, $to, $branch)->sum('amount');
    }

    /** 집계 요약: net, prepaid(합·건), refund(합·건) */
    public static function summary(CarbonInterface $from, CarbonInterface $to, ?int $branch = null): array
    {
        $e = self::entries($from, $to, $branch);
        $p = $e->where('kind', 'prepaid');
        $r = $e->where('kind', 'refund');

        return [
            'net' => (int) $e->sum('amount'),
            'prepaid_amount' => (int) $p->sum('amount'), 'prepaid_count' => $p->count(),
            'refund_amount' => (int) -$r->sum('amount'), 'refund_count' => $r->count(),
        ];
    }
}

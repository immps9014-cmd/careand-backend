<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\KstPeriod;
use App\Support\VoucherRevenue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 결제 내역(관리자, 2026-10-11) — 대시보드 「이번 주 매출」의 건별 목록.
 * 매출 = 카드 결제(payments.status=paid, paid_at 기준) + 산모신생아 바우처 선납(+)·환불(−)(VoucherRevenue).
 * 기간은 KST. 조회 전용(취소·환불은 기존 화면에서).
 */
class PaymentAdminController extends Controller
{
    /**
     * GET /v1/admin/payments?period=today|this_week|last_week|this_month|last_month|custom&from=&to=
     *   &kind=all|card|voucher&status=paid|all&q=&page=&per_page=
     * status=paid(기본)=매출 기준, all=카드 결제 시도 전부(대기·실패·취소 포함, 만든 시각 기준)
     */
    public function index(Request $request): JsonResponse
    {
        [$from, $to, $fromYmd, $toYmd] = KstPeriod::range((string) $request->query('period', 'this_week'), $request->query('from'), $request->query('to'));
        $kind = in_array($request->query('kind'), ['card', 'voucher'], true) ? $request->query('kind') : 'all';
        $status = $request->query('status') === 'all' ? 'all' : 'paid';
        $kw = trim((string) $request->query('q'));
        $perPage = min(max((int) $request->query('per_page', 30), 1), 100);
        $page = max((int) $request->query('page', 1), 1);

        $rows = collect();
        if ($kind !== 'voucher') {
            $timeCol = $status === 'paid' ? 'p.paid_at' : 'p.created_at';
            $card = DB::table('payments as p')
                ->leftJoin('guardians as g', 'g.id', '=', 'p.guardian_id')
                ->leftJoin('users as gu', 'gu.id', '=', 'g.user_id')
                ->leftJoin('matches as m', 'm.id', '=', 'p.match_id')
                ->leftJoin('match_requests as r', 'r.id', '=', 'm.request_id')
                ->leftJoin('caregivers as cg', 'cg.id', '=', 'm.caregiver_id')
                ->leftJoin('users as cu', 'cu.id', '=', 'cg.user_id')
                ->leftJoin('seniors as s', 's.id', '=', 'r.senior_id')
                ->leftJoin('nursing_patients as np', 'np.id', '=', 'r.nursing_patient_id')
                ->leftJoin('postpartum_clients as pp', 'pp.id', '=', 'r.postpartum_client_id')
                ->leftJoin('children as ch', 'ch.id', '=', 'r.childcare_child_id')
                ->leftJoin('mental_care_clients as mcc', 'mcc.id', '=', 'r.mental_care_client_id')
                ->leftJoin('service_addresses as sa', 'sa.id', '=', 'r.service_address_id')
                ->where($timeCol, '>=', $from)->where($timeCol, '<', $to)
                ->when($status === 'paid', fn ($q) => $q->where('p.status', 'paid'))
                ->when($kw !== '', fn ($q) => $q->where(fn ($w) => $w->where('gu.name', 'like', "%{$kw}%")->orWhere('p.pg_order_id', 'like', "%{$kw}%")))
                ->limit(2000)
                ->get([
                    'p.id', 'p.total_amount', 'p.status', 'p.method', 'p.pg_order_id', DB::raw("{$timeCol} as at"), 'p.match_id',
                    'r.id as request_id', 'r.service_domain', 'gu.name as guardian_name', 'cu.name as caregiver_name',
                    DB::raw('COALESCE(s.name, np.name, pp.name, ch.name, mcc.name, sa.label) as recipient_name'),
                ]);
            $rows = $rows->concat($card->map(fn ($p) => [
                'key' => 'p' . $p->id, 'kind' => 'card', 'id' => (int) $p->id,
                'at' => \App\Support\Kst::iso($p->at), 'amount' => (int) round((float) $p->total_amount),
                'status' => $p->status, 'method' => $p->method, 'ref' => $p->pg_order_id,
                'payer_name' => $p->guardian_name, 'recipient_name' => $p->recipient_name, 'caregiver_name' => $p->caregiver_name,
                'service_domain' => $p->service_domain, 'request_id' => $p->request_id ? (int) $p->request_id : null, 'contract_id' => null,
            ]));
        }
        if ($kind !== 'card') {
            // 바우처 선납·환불은 「완료된 돈의 움직임」이라 status=all 이어도 같은 목록
            $entries = VoucherRevenue::entries($from, $to);
            $info = DB::table('mnh_contracts as c')
                ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
                ->leftJoin('postpartum_clients as pc', 'pc.id', '=', 'c.postpartum_client_id')
                ->leftJoin('caregivers as cg', 'cg.id', '=', 'c.caregiver_id')
                ->leftJoin('users as cu', 'cu.id', '=', 'cg.user_id')
                ->whereIn('c.id', $entries->pluck('contract_id')->unique())
                ->get(['c.id', 'c.contract_no', 'c.payment_method', 'u.name as payer_name', 'pc.name as client_name', 'cu.name as caregiver_name'])
                ->keyBy('id');
            $rows = $rows->concat($entries->map(function ($e) use ($info) {
                $c = $info[$e->contract_id] ?? null;

                return [
                    'key' => 'v' . $e->kind . $e->contract_id, 'kind' => $e->kind === 'refund' ? 'voucher_refund' : 'voucher_prepaid', 'id' => (int) $e->contract_id,
                    'at' => \App\Support\Kst::iso($e->at), 'amount' => (int) $e->amount,
                    'status' => $e->kind === 'refund' ? 'refunded' : 'paid', 'method' => $c?->payment_method, 'ref' => $c?->contract_no,
                    'payer_name' => $c?->payer_name, 'recipient_name' => $c?->client_name, 'caregiver_name' => $c?->caregiver_name,
                    'service_domain' => VoucherRevenue::DOMAIN, 'request_id' => null, 'contract_id' => (int) $e->contract_id,
                ];
            })->filter(fn ($r) => $kw === '' || str_contains((string) $r['payer_name'], $kw) || str_contains((string) $r['recipient_name'], $kw) || str_contains((string) $r['ref'], $kw)));
        }

        $rows = $rows->sortByDesc('at')->values();
        $paidCard = $rows->where('kind', 'card')->where('status', 'paid');
        $voucher = $rows->whereIn('kind', ['voucher_prepaid', 'voucher_refund']);
        $summary = [
            'from' => $fromYmd, 'to' => $toYmd,
            // 매출 = 대시보드 「이번 주 매출」과 같은 식(카드 결제 완료 + 바우처 선납 − 환불)
            'revenue' => (int) ($paidCard->sum('amount') + $voucher->sum('amount')),
            'card_paid' => ['count' => $paidCard->count(), 'amount' => (int) $paidCard->sum('amount')],
            'voucher' => ['net' => (int) $voucher->sum('amount'), 'prepaid_count' => $voucher->where('kind', 'voucher_prepaid')->count(), 'refund_count' => $voucher->where('kind', 'voucher_refund')->count()],
            'by_status' => $rows->where('kind', 'card')->countBy('status'),
        ];
        $total = $rows->count();

        return response()->json([
            'success' => true,
            'data' => $rows->slice(($page - 1) * $perPage, $perPage)->values(),
            'summary' => $summary,
            'meta' => ['total' => $total, 'per_page' => $perPage, 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ]);
    }
}

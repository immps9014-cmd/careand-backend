<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 관리자 결제 내역 행·합계(2026-10-11) — 화면(Admin\PaymentAdminController)과 엑셀 다운로드(Admin\ExportController::payments)가 같이 쓴다.
 * 매출 = 카드 결제(payments.status=paid, paid_at 기준) + 산모신생아 바우처 선납(+)·환불(−)(VoucherRevenue). 기간은 KST.
 */
final class AdminPaymentList
{
    /** @return array{rows: \Illuminate\Support\Collection, summary: array} */
    public static function build(Request $request): array
    {
        [$from, $to, $fromYmd, $toYmd] = KstPeriod::range((string) $request->query('period', 'this_week'), $request->query('from'), $request->query('to'));
        $kind = in_array($request->query('kind'), ['card', 'voucher'], true) ? $request->query('kind') : 'all';
        $status = $request->query('status') === 'all' ? 'all' : 'paid';
        $kw = trim((string) $request->query('q'));

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
                    'p.id', 'p.total_amount', 'p.status', 'p.method', 'p.pg_provider', 'p.pg_order_id', DB::raw("{$timeCol} as at"), 'p.match_id',
                    'r.id as request_id', 'r.service_domain', 'gu.name as guardian_name', 'cu.name as caregiver_name',
                    DB::raw('COALESCE(s.name, np.name, pp.name, ch.name, mcc.name, sa.label) as recipient_name'),
                ]);
            $rows = $rows->concat($card->map(fn ($p) => [
                'key' => 'p' . $p->id, 'kind' => 'card', 'id' => (int) $p->id,
                'at' => Kst::iso($p->at), 'amount' => (int) round((float) $p->total_amount),
                'status' => $p->status, 'method' => $p->method, 'ref' => $p->pg_order_id,
                // 앱 밖에서 받아 관리자가 기록한 결제(현금·계좌이체·현장 단말기, 10-18)
                'offline' => $p->pg_provider === 'offline',
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
                    // 위약금 차액은 계약 하나에 여러 번 받을 수 있어 받은 기록 id 로 키를 만든다
                    'key' => 'v' . $e->kind . $e->contract_id . (isset($e->row_id) ? '-' . $e->row_id : ''),
                    'kind' => ['refund' => 'voucher_refund', 'penalty' => 'voucher_penalty'][$e->kind] ?? 'voucher_prepaid', 'id' => (int) $e->contract_id,
                    'at' => Kst::iso($e->at), 'amount' => (int) $e->amount,
                    'status' => $e->kind === 'refund' ? 'refunded' : 'paid', 'method' => $e->method ?? $c?->payment_method, 'ref' => $c?->contract_no,
                    'payer_name' => $c?->payer_name, 'recipient_name' => $c?->client_name, 'caregiver_name' => $c?->caregiver_name,
                    'service_domain' => VoucherRevenue::DOMAIN, 'request_id' => null, 'contract_id' => (int) $e->contract_id,
                ];
            })->filter(fn ($r) => $kw === '' || str_contains((string) $r['payer_name'], $kw) || str_contains((string) $r['recipient_name'], $kw) || str_contains((string) $r['ref'], $kw)));
        }

        $rows = $rows->sortByDesc('at')->values();
        $paidCard = $rows->where('kind', 'card')->where('status', 'paid');
        $voucher = $rows->whereIn('kind', ['voucher_prepaid', 'voucher_refund', 'voucher_penalty']);
        $summary = [
            'from' => $fromYmd, 'to' => $toYmd,
            // 매출 = 대시보드 「이번 주 매출」과 같은 식(카드 결제 완료 + 바우처 선납 − 환불)
            'revenue' => (int) ($paidCard->sum('amount') + $voucher->sum('amount')),
            'card_paid' => ['count' => $paidCard->count(), 'amount' => (int) $paidCard->sum('amount')],
            'voucher' => ['net' => (int) $voucher->sum('amount'), 'prepaid_count' => $voucher->where('kind', 'voucher_prepaid')->count(), 'refund_count' => $voucher->where('kind', 'voucher_refund')->count(),
                'penalty_count' => $voucher->where('kind', 'voucher_penalty')->count()],
            'by_status' => $rows->where('kind', 'card')->countBy('status'),
        ];

        return ['rows' => $rows, 'summary' => $summary];
    }
}

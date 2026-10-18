<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\MatchPaid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 앱 밖에서 받은 결제(현금·계좌이체·현장 카드 단말기)를 관리자가 기록한다(2026-10-18, 관리자 수동 매칭 결제 예외).
 * 결제 전 출근 차단(CHECKIN_REQUIRES_PAYMENT)은 그대로 두고, 운영팀이 돈을 받았다고 확인한 매칭만 payments 에
 * pg_provider='offline'·status='paid' 한 줄로 남긴다 → MatchPaid 가 결제 완료로 보고 출근이 열리며, 매출·결제 내역에도 같이 잡힌다.
 * 바우처 계약 매칭은 선납(mnh_contracts.prepaid_at)으로 처리하므로 여기서 받지 않는다.
 */
class OfflinePaymentController extends Controller
{
    public const PROVIDER = 'offline';
    public const METHODS = ['cash' => '현금', 'transfer' => '계좌이체', 'card' => '카드(현장 단말기)'];

    /** 요청의 현재 매칭(진행 가능한 것) */
    private function match(int $requestId): ?object
    {
        return DB::table('matches as m')->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->where('m.request_id', $requestId)->whereIn('m.status', ['confirmed', 'in_progress', 'completed'])
            ->orderByDesc('m.id')->first(['m.id', 'm.estimated_amount', 'm.is_manual', 'r.guardian_id', 'r.mnh_contract_id']);
    }

    /** 상세 화면용 결제 상태 — OperationsController::matchingRequestDetail 이 쓴다 */
    public static function summary(int $matchId): array
    {
        if (MatchPaid::isVoucherContract($matchId)) {
            return ['state' => MatchPaid::is($matchId) ? 'voucher_prepaid' : 'voucher', 'payment' => null];
        }
        $p = DB::table('payments')->where('match_id', $matchId)->where('status', 'paid')->orderByDesc('id')->first();
        if (!$p) {
            return ['state' => 'unpaid', 'payment' => null];
        }
        $meta = json_decode((string) $p->pg_response, true) ?: [];

        return ['state' => 'paid', 'payment' => [
            'id' => (int) $p->id,
            'offline' => $p->pg_provider === self::PROVIDER,
            'method' => $p->method,
            'method_label' => $p->pg_provider === self::PROVIDER ? (self::METHODS[$p->method] ?? $p->method) : ($p->method === 'card' ? '카드(앱 결제)' : $p->method),
            'amount' => (int) round((float) $p->total_amount),
            'paid_at' => \App\Support\Kst::iso($p->paid_at),
            'note' => $meta['note'] ?? null,
            'recorded_by' => $meta['recorded_by_name'] ?? null,
        ]];
    }

    /** POST admin/matching/requests/{id}/offline-payment {method, amount, paid_on, note} */
    public function store(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'in:' . implode(',', array_keys(self::METHODS))],
            'amount' => ['required', 'integer', 'min:1', 'max:10000000'],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $today = Carbon::now('Asia/Seoul')->toDateString();
        if ($data['paid_on'] > $today) {
            return response()->json(['success' => false, 'error_code' => 'FUTURE_DATE', 'message' => '받은 날은 오늘이나 그 전이어야 해요.'], 422);
        }
        $m = $this->match($id);
        if (!$m) {
            return response()->json(['success' => false, 'error_code' => 'NO_MATCH', 'message' => '아직 매칭되지 않은 요청이에요. 먼저 배정하세요.'], 422);
        }
        if ($m->mnh_contract_id) {
            return response()->json(['success' => false, 'error_code' => 'VOUCHER_CONTRACT', 'message' => '바우처 계약은 계약 화면에서 본인부담금 선납으로 기록하세요.'], 422);
        }
        if (MatchPaid::is((int) $m->id)) {
            return response()->json(['success' => false, 'error_code' => 'ALREADY_PAID', 'message' => '이미 결제 완료된 매칭이에요.'], 422);
        }

        $admin = $request->user();
        // 받은 날이 오늘이면 지금 시각, 지난 날이면 그날 정오(KST) — 매출은 paid_at 기준으로 그날에 잡힌다
        $paidAt = $data['paid_on'] === $today ? now() : Carbon::parse($data['paid_on'] . ' 12:00:00', 'Asia/Seoul')->utc();
        $pid = DB::transaction(function () use ($m, $data, $admin, $paidAt) {
            // 앱 결제창에서 만들다 만 대기 건은 닫아 둔다(보호자가 이어서 결제하지 않게)
            DB::table('payments')->where('match_id', $m->id)->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);

            return DB::table('payments')->insertGetId([
                'guardian_id' => $m->guardian_id,
                'match_id' => $m->id,
                'total_amount' => $data['amount'],
                'amount_self_pay' => $data['amount'],
                'amount_ltc_pay' => 0,
                'method' => $data['method'],
                'pg_provider' => self::PROVIDER,
                'status' => 'paid',
                'pg_response' => json_encode([
                    'offline' => true, 'note' => $data['note'] ?? null, 'paid_on' => $data['paid_on'],
                    'recorded_by' => $admin?->id, 'recorded_by_name' => $admin?->name, 'recorded_at' => now()->toIso8601String(),
                ], JSON_UNESCAPED_UNICODE),
                'paid_at' => $paidAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['success' => true, 'message' => '현장 결제를 기록했어요. 이제 돌봄전문가가 출근할 수 있어요.', 'data' => self::summary((int) $m->id) + ['payment_id' => $pid]]);
    }

    /** DELETE admin/matching/requests/{id}/offline-payment/{paymentId} — 잘못 기록한 것만 되돌린다(취소 상태로 남김) */
    public function destroy(Request $request, int $id, int $paymentId): JsonResponse
    {
        $p = DB::table('payments as p')->join('matches as m', 'm.id', '=', 'p.match_id')
            ->where('p.id', $paymentId)->where('m.request_id', $id)->first(['p.id', 'p.pg_provider', 'p.status', 'p.pg_response', 'p.match_id']);
        if (!$p || $p->pg_provider !== self::PROVIDER) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => '현장 결제 기록을 찾을 수 없어요.'], 404);
        }
        if ($p->status !== 'paid') {
            return response()->json(['success' => false, 'error_code' => 'NOT_PAID', 'message' => '이미 취소된 기록이에요.'], 422);
        }
        $meta = json_decode((string) $p->pg_response, true) ?: [];
        $meta['cancelled_by'] = $request->user()?->id;
        $meta['cancelled_at'] = now()->toIso8601String();
        $meta['cancel_reason'] = mb_substr((string) $request->input('reason', ''), 0, 200) ?: null;
        DB::table('payments')->where('id', $p->id)->update([
            'status' => 'cancelled', 'pg_response' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => '현장 결제 기록을 취소했어요. 다시 결제가 확인되기 전까지 출근이 막혀요.', 'data' => self::summary((int) $p->match_id)]);
    }
}

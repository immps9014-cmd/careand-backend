<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\ApprovePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\CareMatch;
use App\Models\LtcVoucher;
use App\Models\Payment;
use App\Models\PaymentItem;
use App\Services\External\NhisService;
use App\Services\External\PgService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function __construct(
        private NhisService $nhisService,
        private PgService $pgService,
    ) {
    }

    /**
     * GET /v1/payments
     * 보호자 본인의 결제 내역
     */
    public function index(Request $request): JsonResponse
    {
        $guardian = $request->user()->guardian;
        if (!$guardian) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_GUARDIAN',
                'message' => '보호자 회원만 조회 가능합니다.',
            ], 403);
        }

        $payments = Payment::where('guardian_id', $guardian->id)
            ->with(['match.request.senior:id,name', 'items'])
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => PaymentResource::collection($payments),
            'meta' => [
                'total' => $payments->total(),
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
            ],
        ]);
    }

    /**
     * POST /v1/payments/calculate
     * 결제 전 자동 분리 계산 (본인부담금 vs 장기요양 청구분)
     *
     * Request: { match_id }
     * Response: { total_amount, self_pay, ltc_pay, voucher_remaining }
     */
    public function calculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'match_id' => ['required', 'exists:matches,id'],
        ]);

        $match = CareMatch::with('request.senior')->findOrFail($validated['match_id']);
        $this->authorize('view', $match);

        $senior = $match->request->senior;
        $totalAmount = (int) $match->estimated_amount;

        // 1. 이번 달 바우처 조회
        $voucher = LtcVoucher::where('senior_id', $senior->id)
            ->where('period_month', now()->startOfMonth()->toDateString())
            ->first();

        if (!$voucher) {
            return response()->json([
                'success' => false,
                'error_code' => 'NO_VOUCHER',
                'message' => '이번 달 장기요양 바우처가 없습니다. 어르신 정보를 갱신해주세요.',
            ], 422);
        }

        // 2. 자동 분리 계산
        $split = $this->nhisService->calculateSplit(
            totalAmount: $totalAmount,
            careGrade: $senior->care_grade,
            copayRate: $voucher->copay_rate,
        );

        // 3. 한도 초과 체크
        if ($split['ltc_pay'] > $voucher->remaining_amount) {
            $exceeded = $split['ltc_pay'] - $voucher->remaining_amount;
            // 한도 초과분은 자비로 전환
            $split['self_pay'] += $exceeded;
            $split['ltc_pay'] = $voucher->remaining_amount;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'match_id' => $match->id,
                'total_amount' => $totalAmount,
                'self_pay' => $split['self_pay'],
                'ltc_pay' => $split['ltc_pay'],
                'copay_rate' => $voucher->copay_rate,
                'voucher_remaining' => $voucher->remaining_amount,
                'voucher_after_payment' => max(0, $voucher->remaining_amount - $split['ltc_pay']),
            ],
        ]);
    }

    /**
     * POST /v1/payments/approve
     * 본인부담금 PG 결제 승인 + 바우처 차감
     *
     * 헤더: Idempotency-Key (재시도 안전성)
     */
    public function approve(ApprovePaymentRequest $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key', Str::uuid()->toString());

        // 중복 요청 방지
        if ($existing = Payment::where('idempotency_key', $idempotencyKey)->first()) {
            return response()->json([
                'success' => true,
                'message' => '이미 처리된 결제입니다.',
                'data' => new PaymentResource($existing->load('items')),
            ], 200);
        }

        $data = $request->validated();
        $match = CareMatch::with('request.senior')->findOrFail($data['match_id']);
        $this->authorize('view', $match);

        // 다시 분리 계산 (서버 신뢰)
        $senior = $match->request->senior;
        $totalAmount = (int) $match->estimated_amount;

        $voucher = LtcVoucher::where('senior_id', $senior->id)
            ->where('period_month', now()->startOfMonth()->toDateString())
            ->lockForUpdate()
            ->first();

        if (!$voucher) {
            return response()->json([
                'success' => false,
                'error_code' => 'NO_VOUCHER',
                'message' => '장기요양 바우처가 없습니다.',
            ], 422);
        }

        $split = $this->nhisService->calculateSplit($totalAmount, $senior->care_grade, $voucher->copay_rate);
        if ($split['ltc_pay'] > $voucher->remaining_amount) {
            $exceeded = $split['ltc_pay'] - $voucher->remaining_amount;
            $split['self_pay'] += $exceeded;
            $split['ltc_pay'] = $voucher->remaining_amount;
        }

        // PG 결제 + DB 저장 트랜잭션
        try {
            $payment = DB::transaction(function () use (
                $request, $data, $match, $totalAmount, $split, $voucher, $idempotencyKey
            ) {
                // 1. payments INSERT (status=pending)
                $payment = Payment::create([
                    'guardian_id' => $request->user()->guardian->id,
                    'match_id' => $match->id,
                    'total_amount' => $totalAmount,
                    'amount_self_pay' => $split['self_pay'],
                    'amount_ltc_pay' => $split['ltc_pay'],
                    'method' => $data['method'],
                    'pg_provider' => config('services.pg.provider'),
                    'idempotency_key' => $idempotencyKey,
                    'status' => 'pending',
                ]);

                // 2. PG 승인 (자비 부분만)
                if ($split['self_pay'] > 0 && $data['method'] !== 'voucher_only') {
                    $pgResult = $this->pgService->approve([
                        'amount' => $split['self_pay'],
                        'order_id' => "ORD_{$payment->id}_" . now()->format('YmdHis'),
                        'card_token' => $data['card_token'] ?? '',
                        'customer' => [
                            'name' => $request->user()->name,
                            'phone' => $request->user()->phone,
                        ],
                    ]);

                    if (!$pgResult['success']) {
                        $payment->update([
                            'status' => 'failed',
                            'pg_response' => $pgResult['raw'],
                        ]);
                        throw new \RuntimeException("PG 결제 실패: {$pgResult['message']}");
                    }

                    $payment->update([
                        'pg_tid' => $pgResult['pg_tid'],
                        'pg_response' => $pgResult['raw'],
                    ]);
                }

                // 3. payment_items
                if ($split['self_pay'] > 0) {
                    PaymentItem::create([
                        'payment_id' => $payment->id,
                        'item_type' => 'self_pay',
                        'amount' => $split['self_pay'],
                        'description' => '본인부담금',
                    ]);
                }
                if ($split['ltc_pay'] > 0) {
                    PaymentItem::create([
                        'payment_id' => $payment->id,
                        'item_type' => 'ltc_pay',
                        'amount' => $split['ltc_pay'],
                        'description' => '장기요양 청구분',
                    ]);
                }

                // 4. 바우처 차감
                if ($split['ltc_pay'] > 0) {
                    $voucher->update([
                        'used_amount' => $voucher->used_amount + $split['ltc_pay'],
                        'remaining_amount' => $voucher->remaining_amount - $split['ltc_pay'],
                    ]);
                }

                // 5. 결제 완료
                $payment->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);

                return $payment;
            });

            return response()->json([
                'success' => true,
                'message' => '결제가 완료되었습니다.',
                'data' => new PaymentResource($payment->load('items')),
            ]);
        } catch (\Throwable $e) {
            Log::error('결제 처리 실패', [
                'match_id' => $match->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'error_code' => 'PAYMENT_FAILED',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /v1/payments/{id}/cancel
     * 결제 취소 + 바우처 환원
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $payment = Payment::with('match.request.senior')->findOrFail($id);
        $this->authorize('update', $payment);

        if ($payment->status !== 'paid') {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_CANCELLABLE',
                'message' => '결제완료 상태만 취소 가능합니다.',
            ], 422);
        }

        $reason = $request->input('reason', '보호자 요청');

        try {
            DB::transaction(function () use ($payment, $reason) {
                // 1. PG 취소
                if ($payment->pg_tid && $payment->amount_self_pay > 0) {
                    $this->pgService->cancel(
                        $payment->pg_tid,
                        (int) $payment->amount_self_pay,
                        $reason
                    );
                }

                // 2. 바우처 환원
                if ($payment->amount_ltc_pay > 0) {
                    $voucher = LtcVoucher::where('senior_id', $payment->match->request->senior->id)
                        ->where('period_month', now()->startOfMonth()->toDateString())
                        ->first();
                    if ($voucher) {
                        $voucher->update([
                            'used_amount' => max(0, $voucher->used_amount - $payment->amount_ltc_pay),
                            'remaining_amount' => $voucher->remaining_amount + $payment->amount_ltc_pay,
                        ]);
                    }
                }

                // 3. payment 상태 변경
                $payment->update(['status' => 'cancelled']);
            });

            return response()->json([
                'success' => true,
                'message' => '결제가 취소되었습니다.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error_code' => 'CANCEL_FAILED',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /v1/webhooks/pg
     * PG 비동기 콜백 (정산 대사용)
     */
    public function pgWebhook(Request $request): JsonResponse
    {
        $signature = $request->header('X-PG-Signature', '');
        $payload = $request->getContent();

        if (!$this->pgService->verifyWebhookSignature($payload, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $data = $request->all();
        $pgTid = $data['tid'] ?? null;
        $status = $data['status'] ?? null;

        $payment = Payment::where('pg_tid', $pgTid)->first();
        if (!$payment) {
            return response()->json(['error' => 'Payment not found'], 404);
        }

        Log::info('PG 웹훅 수신', ['pg_tid' => $pgTid, 'status' => $status]);

        // 상태 동기화
        $payment->update([
            'pg_response' => array_merge($payment->pg_response ?? [], ['webhook' => $data]),
        ]);

        return response()->json(['ok' => true]);
    }
}

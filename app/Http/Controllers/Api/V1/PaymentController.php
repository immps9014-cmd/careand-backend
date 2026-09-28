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
use App\Services\External\TossPaymentsService;
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

        $totalAmount = (int) $match->estimated_amount;

        // 비급여 도메인(간병·가사 등): 바우처 없이 100% 본인부담
        if ($match->request->service_domain !== 'senior') {
            return response()->json([
                'success' => true,
                'data' => [
                    'match_id' => $match->id,
                    'total_amount' => $totalAmount,
                    'self_pay' => $totalAmount,
                    'ltc_pay' => 0,
                    'copay_rate' => null,
                    'voucher_remaining' => null,
                    'voucher_after_payment' => null,
                ],
            ]);
        }

        $senior = $match->request->senior;

        // 1. 이번 달 바우처 조회
        $voucher = LtcVoucher::where('senior_id', $senior->id)
            ->where('period_month', now()->startOfMonth()->toDateString())
            ->first();

        // 장기요양 등급이 없거나(등급외·미신청) 바우처가 없는 어르신은
        // 공단 부담분이 없으므로 100% 본인부담으로 결제를 진행한다.
        if (!$voucher || !$senior->care_grade) {
            return response()->json([
                'success' => true,
                'data' => [
                    'match_id' => $match->id,
                    'total_amount' => $totalAmount,
                    'self_pay' => $totalAmount,
                    'ltc_pay' => 0,
                    'copay_rate' => null,
                    'voucher_remaining' => null,
                    'voucher_after_payment' => null,
                ],
            ]);
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
                // copay_rate 는 % 정수(15)로 저장 → 프론트가 소수로 다루므로 비율로 변환
                'copay_rate' => $voucher->copay_rate / 100,
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

        // 다시 분리 계산 (서버 신뢰) — 토스 결제 준비와 같은 함수(resolveSplit)
        $totalAmount = (int) $match->estimated_amount;
        $resolved = $this->resolveSplit($match, $data['method'] ?? null);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        ['split' => $split, 'voucher' => $voucher] = $resolved;

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
    /**
     * 본인부담·장기요양 분할 — 결제 정책 한 곳(approve·토스 준비 공용, 2026-09-28 S4 에서 추출).
     * 장기요양(시니어) 도메인은 등급·당월 바우처로 나누고 바우처 잔액을 넘으면 본인부담으로 돌린다.
     *
     * @return array{split: array{self_pay: int, ltc_pay: int}, voucher: ?LtcVoucher}|JsonResponse
     */
    private function resolveSplit(CareMatch $match, ?string $method): array|JsonResponse
    {
        $totalAmount = (int) $match->estimated_amount;
        $isLtcDomain = $match->request->service_domain === 'senior';

        if ($isLtcDomain) {
            $senior = $match->request->senior;

            $voucher = LtcVoucher::where('senior_id', $senior->id)
                ->where('period_month', now()->startOfMonth()->toDateString())
                ->lockForUpdate()
                ->first();

            // 등급/바우처가 없으면 공단 부담 없이 100% 본인부담 (calculate 와 동일 정책)
            if (!$voucher || !$senior->care_grade) {
                if ($method === 'voucher_only') {
                    return response()->json([
                        'success' => false,
                        'error_code' => 'INVALID_METHOD',
                        'message' => '장기요양 바우처를 사용할 수 없습니다.',
                    ], 422);
                }
                $voucher = null;
                $split = ['self_pay' => $totalAmount, 'ltc_pay' => 0];
            } else {
                $split = $this->nhisService->calculateSplit($totalAmount, $senior->care_grade, $voucher->copay_rate);
                if ($split['ltc_pay'] > $voucher->remaining_amount) {
                    $exceeded = $split['ltc_pay'] - $voucher->remaining_amount;
                    $split['self_pay'] += $exceeded;
                    $split['ltc_pay'] = $voucher->remaining_amount;
                }
            }
        } else {
            // 비급여 도메인(간병·가사 등): 100% 본인부담, 바우처 결제 불가
            if ($method === 'voucher_only') {
                return response()->json([
                    'success' => false,
                    'error_code' => 'INVALID_METHOD',
                    'message' => '장기요양 바우처를 사용할 수 없는 서비스입니다.',
                ], 422);
            }
            $voucher = null;
            $split = ['self_pay' => $totalAmount, 'ltc_pay' => 0];
        }


        return ['split' => $split, 'voucher' => $voucher];
    }

    /**
     * POST /v1/payments/toss/prepare {match_id, method: card|account}
     * 토스 결제창을 열기 전 — 서버가 금액을 다시 계산하고 주문번호를 발급한다(결제 대기 기록 생성).
     * 본인부담이 0원(바우처로 전액)이면 결제창 없이 기존 승인 경로를 쓰도록 알린다.
     */
    public function tossPrepare(Request $request, TossPaymentsService $toss): JsonResponse
    {
        $data = $request->validate([
            'match_id' => ['required', 'exists:matches,id'],
            'method' => ['required', 'in:card,account'],
        ]);
        $match = CareMatch::with('request.senior')->findOrFail($data['match_id']);
        $this->authorize('view', $match);
        if (Payment::where('match_id', $match->id)->where('status', 'paid')->exists()) {
            return response()->json(['success' => false, 'error_code' => 'ALREADY_PAID', 'message' => '이미 결제가 완료된 매칭입니다.'], 422);
        }

        $resolved = $this->resolveSplit($match, $data['method']);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        $split = $resolved['split'];
        if ($split['self_pay'] <= 0) {
            return response()->json(['success' => true, 'data' => ['toss_required' => false]]);
        }

        $payment = Payment::create([
            'guardian_id' => $request->user()->guardian->id,
            'match_id' => $match->id,
            'total_amount' => (int) $match->estimated_amount,
            'amount_self_pay' => $split['self_pay'],
            'amount_ltc_pay' => $split['ltc_pay'],
            'method' => $data['method'],
            'pg_provider' => 'toss',
            'idempotency_key' => (string) Str::uuid(),
            'status' => 'pending',
        ]);
        $orderId = 'CAREN-' . $payment->id . '-' . Str::lower(Str::random(8));
        $payment->update(['pg_order_id' => $orderId]);

        $domainLabel = config('service_domains.' . $match->request->service_domain . '.label', '돌봄');
        return response()->json(['success' => true, 'data' => [
            'toss_required' => true,
            'client_key' => $toss->clientKey(),
            'test_mode' => $toss->isTestMode(),
            'order_id' => $orderId,
            'order_name' => '케어앤 ' . $domainLabel . ' 서비스',
            'amount' => (int) $split['self_pay'],
            'customer_key' => 'caren-g' . $request->user()->guardian->id,
            'customer_name' => $request->user()->name,
        ]]);
    }

    /**
     * POST /v1/payments/toss/confirm {payment_key, order_id, amount}
     * 결제창 성공 후 — 금액이 서버 기록과 같은지 확인하고 토스에 승인 요청, 승인되면 항목·바우처 차감·완료 처리.
     */
    public function tossConfirm(Request $request, TossPaymentsService $toss): JsonResponse
    {
        $data = $request->validate([
            'payment_key' => ['required', 'string', 'max:200'],
            'order_id' => ['required', 'string', 'max:64'],
            'amount' => ['required', 'integer', 'min:1'],
        ]);
        $payment = Payment::where('pg_order_id', $data['order_id'])
            ->where('guardian_id', $request->user()->guardian?->id)->first();
        if (!$payment) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => '결제 요청을 찾을 수 없습니다.'], 404);
        }
        if ($payment->status === 'paid') {   // 새로고침 등 중복 승인 요청
            return response()->json(['success' => true, 'message' => '이미 처리된 결제입니다.', 'data' => new PaymentResource($payment->load('items'))]);
        }
        if ($payment->status !== 'pending') {
            return response()->json(['success' => false, 'error_code' => 'INVALID_STATUS', 'message' => '처리할 수 없는 결제 상태입니다.'], 422);
        }
        // 금액 위변조 방지 — 결제창이 돌려준 금액과 서버가 계산해 둔 본인부담이 같아야 한다
        if ((int) $data['amount'] !== (int) $payment->amount_self_pay) {
            $payment->update(['status' => 'failed', 'pg_response' => ['reason' => 'amount_mismatch', 'requested' => $data['amount']]]);
            return response()->json(['success' => false, 'error_code' => 'AMOUNT_MISMATCH', 'message' => '결제 금액이 일치하지 않습니다.'], 422);
        }

        $res = $toss->confirm($data['payment_key'], $data['order_id'], (int) $data['amount']);
        if (!$res['ok']) {
            $payment->update(['status' => 'failed', 'pg_tid' => $data['payment_key'], 'pg_response' => $res['data']]);
            return response()->json(['success' => false, 'error_code' => 'PG_FAILED', 'message' => $res['message'] ?? '결제 승인에 실패했습니다.'], 422);
        }

        DB::transaction(function () use ($payment, $data, $res) {
            $payment->update(['pg_tid' => $data['payment_key'], 'pg_response' => $res['data']]);
            PaymentItem::create(['payment_id' => $payment->id, 'item_type' => 'self_pay', 'amount' => $payment->amount_self_pay, 'description' => '본인부담금']);
            if ($payment->amount_ltc_pay > 0) {
                PaymentItem::create(['payment_id' => $payment->id, 'item_type' => 'ltc_pay', 'amount' => $payment->amount_ltc_pay, 'description' => '장기요양 청구분']);
                $seniorId = $payment->match?->request?->senior_id;
                $voucher = $seniorId ? LtcVoucher::where('senior_id', $seniorId)
                    ->where('period_month', now()->startOfMonth()->toDateString())->lockForUpdate()->first() : null;
                if ($voucher) {
                    $voucher->update([
                        'used_amount' => $voucher->used_amount + $payment->amount_ltc_pay,
                        'remaining_amount' => max(0, $voucher->remaining_amount - $payment->amount_ltc_pay),
                    ]);
                }
            }
            $payment->update(['status' => 'paid', 'paid_at' => now()]);
        });

        return response()->json(['success' => true, 'message' => '결제가 완료되었습니다.', 'data' => new PaymentResource($payment->fresh()->load('items'))]);
    }

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
                if ($payment->pg_provider === 'toss' && $payment->pg_tid && $payment->amount_self_pay > 0) {
                    // 토스페이먼츠 결제 취소(S4)
                    $r = app(TossPaymentsService::class)->cancel($payment->pg_tid, (string) ($request->input('reason') ?: '고객 요청 취소'));
                    if (!$r['ok']) {
                        throw new \RuntimeException('PG 결제 취소 실패: ' . ($r['message'] ?? ''));
                    }
                } elseif ($payment->pg_tid && $payment->amount_self_pay > 0) {
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

<?php

namespace App\Services\External;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 토스페이먼츠 결제 승인·취소·조회 — 사업계획서 기능 4·31 (2026-09-28, 구현계획 S4).
 * 흐름: 회원 웹 결제창(requestPayment) → successUrl 로 paymentKey·orderId·amount → 서버 confirm.
 * 인증: 시크릿 키 Basic(base64("secret:")). 테스트 키(test_)는 샌드박스 — 실제 청구 없음.
 * PG 만 따로 켜는 스위치 services.pg.live(PG_LIVE) — 외부연동 전체 스텁(EXTERNAL_STUB)과 별개로 샌드박스 연결 가능.
 */
class TossPaymentsService
{
    private const BASE = 'https://api.tosspayments.com/v1';

    public function live(): bool
    {
        return (bool) config('services.pg.live') || !config('services.external.stub');
    }

    public function clientKey(): ?string
    {
        return config('services.pg.toss_client_key');
    }

    public function isTestMode(): bool
    {
        return str_starts_with((string) config('services.pg.toss_secret_key'), 'test_');
    }

    /** @return array{ok: bool, status: ?string, data: array, message: ?string} */
    public function confirm(string $paymentKey, string $orderId, int $amount): array
    {
        if (!$this->live()) {
            return ['ok' => true, 'status' => 'DONE', 'data' => ['paymentKey' => $paymentKey, 'orderId' => $orderId, 'totalAmount' => $amount, 'method' => '카드', 'stub' => true], 'message' => null];
        }
        return $this->post('/payments/confirm', ['paymentKey' => $paymentKey, 'orderId' => $orderId, 'amount' => $amount], "confirm:{$orderId}");
    }

    public function cancel(string $paymentKey, string $reason, ?int $amount = null): array
    {
        if (!$this->live()) {
            return ['ok' => true, 'status' => 'CANCELED', 'data' => ['stub' => true], 'message' => null];
        }
        $body = ['cancelReason' => mb_substr($reason, 0, 200)] + ($amount !== null ? ['cancelAmount' => $amount] : []);
        return $this->post("/payments/{$paymentKey}/cancel", $body, "cancel:{$paymentKey}");
    }

    public function get(string $paymentKey): array
    {
        $res = $this->http()->get(self::BASE . "/payments/{$paymentKey}");
        return ['ok' => $res->successful(), 'status' => $res->json('status'), 'data' => $res->json() ?? [], 'message' => $res->json('message')];
    }

    private function post(string $path, array $body, string $idempotency): array
    {
        try {
            $res = $this->http()->withHeaders(['Idempotency-Key' => substr(hash('sha256', $idempotency), 0, 60)])
                ->post(self::BASE . $path, $body);
            $data = $res->json() ?? [];
            $ok = $res->successful() && in_array($data['status'] ?? '', ['DONE', 'CANCELED', 'PARTIAL_CANCELED', 'WAITING_FOR_DEPOSIT'], true);
            if (!$ok) {
                Log::warning('토스페이먼츠 실패', ['path' => $path, 'http' => $res->status(), 'code' => $data['code'] ?? null, 'message' => $data['message'] ?? null]);
            }
            return ['ok' => $ok, 'status' => $data['status'] ?? null, 'data' => $data, 'message' => $data['message'] ?? null];
        } catch (\Throwable $e) {
            Log::error('토스페이먼츠 호출 예외: ' . $e->getMessage());
            return ['ok' => false, 'status' => null, 'data' => [], 'message' => '결제사 연결에 실패했습니다.'];
        }
    }

    private function http()
    {
        return Http::timeout(20)->withBasicAuth((string) config('services.pg.toss_secret_key'), '')->acceptJson()->asJson();
    }
}

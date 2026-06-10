<?php

namespace App\Services\External;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PG사 결제 연동 (KG이니시스 기준)
 *
 * 실제 결제는 SDK/모듈 사용 권장. 본 클래스는 서버 검증/취소용.
 */
class PgService
{
    public function __construct(
        private readonly string $provider,
        private readonly string $mid,
        private readonly string $apiKey,
        private readonly ?string $signKey = null,
    ) {
    }

    /**
     * 결제 승인 (서버 to 서버)
     *
     * @param array{amount: int, order_id: string, card_token: string, customer: array} $payload
     * @return array{success: bool, pg_tid: ?string, message: ?string, raw: array}
     */
    public function approve(array $payload): array
    {
        if (app()->environment('local', 'testing')) {
            return $this->mockApprove($payload);
        }

        try {
            $response = Http::timeout(15)
                ->asForm()
                ->withHeaders(['Authorization' => "Bearer {$this->apiKey}"])
                ->post($this->getProviderUrl('approve'), [
                    'mid' => $this->mid,
                    'amount' => $payload['amount'],
                    'orderId' => $payload['order_id'],
                    'cardToken' => $payload['card_token'],
                ]);

            $data = $response->json();

            if ($response->successful() && ($data['resultCode'] ?? '') === '0000') {
                return [
                    'success' => true,
                    'pg_tid' => $data['tid'] ?? null,
                    'message' => $data['resultMsg'] ?? null,
                    'raw' => $data,
                ];
            }

            return [
                'success' => false,
                'pg_tid' => null,
                'message' => $data['resultMsg'] ?? 'PG 결제 실패',
                'raw' => $data,
            ];
        } catch (\Throwable $e) {
            Log::error('PG 결제 승인 실패', ['error' => $e->getMessage(), 'payload' => $payload]);
            throw new RuntimeException('결제 처리 중 오류가 발생했습니다.');
        }
    }

    /**
     * 결제 취소
     */
    public function cancel(string $pgTid, int $amount, string $reason): array
    {
        if (app()->environment('local', 'testing')) {
            return ['success' => true, 'pg_tid' => $pgTid, 'message' => 'mock cancel', 'raw' => []];
        }

        try {
            $response = Http::timeout(15)
                ->asForm()
                ->withHeaders(['Authorization' => "Bearer {$this->apiKey}"])
                ->post($this->getProviderUrl('cancel'), [
                    'mid' => $this->mid,
                    'tid' => $pgTid,
                    'amount' => $amount,
                    'reason' => $reason,
                ]);

            $data = $response->json();
            $success = ($data['resultCode'] ?? '') === '0000';

            return [
                'success' => $success,
                'pg_tid' => $pgTid,
                'message' => $data['resultMsg'] ?? null,
                'raw' => $data,
            ];
        } catch (\Throwable $e) {
            Log::error('PG 결제 취소 실패', ['error' => $e->getMessage()]);
            throw new RuntimeException('결제 취소 중 오류가 발생했습니다.');
        }
    }

    /**
     * 웹훅 서명 검증 (HMAC-SHA256)
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if (!$this->signKey) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $this->signKey);
        return hash_equals($expected, $signature);
    }

    private function getProviderUrl(string $action): string
    {
        return match ($this->provider) {
            'kg_inicis' => match ($action) {
                'approve' => 'https://stdpay.inicis.com/api/payment/approval',
                'cancel' => 'https://iniapi.inicis.com/api/v1/refund',
                default => throw new RuntimeException("Unknown action: {$action}"),
            },
            'toss' => match ($action) {
                'approve' => 'https://api.tosspayments.com/v1/payments/confirm',
                'cancel' => 'https://api.tosspayments.com/v1/payments/{paymentKey}/cancel',
                default => throw new RuntimeException("Unknown action: {$action}"),
            },
            default => throw new RuntimeException("Unsupported PG provider: {$this->provider}"),
        };
    }

    private function mockApprove(array $payload): array
    {
        // 테스트: order_id에 'fail' 포함 시 실패 처리
        if (str_contains($payload['order_id'], 'fail')) {
            return [
                'success' => false,
                'pg_tid' => null,
                'message' => '카드 한도 초과',
                'raw' => ['resultCode' => '7001'],
            ];
        }

        return [
            'success' => true,
            'pg_tid' => 'INI_' . strtoupper(substr(md5(uniqid()), 0, 16)),
            'message' => '결제 승인',
            'raw' => ['resultCode' => '0000'],
        ];
    }
}

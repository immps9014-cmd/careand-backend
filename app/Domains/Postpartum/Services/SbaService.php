<?php

namespace App\Domains\Postpartum\Services;

use App\Domains\Postpartum\Models\PostpartumClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SBA — 사회서비스 전자바우처 API 연동
 *
 * 사용 시나리오:
 *  - inquireEligibility — 산모 자격 + 등급 + 본인부담률 조회
 *
 * 바우처 결제(차감·환불)는 다루지 않는다(2026-10-18 운영사 결정): 관리사가 정부 단말기로 결제하고
 * 그 기록은 정부 전자바우처 시스템에만 남는다. 플랫폼은 단말기 결제를 기록·대사하지 않는다.
 * 옛 useVoucher/refundVoucher·VoucherController(/postpartum/voucher-transactions)는 이때 지웠다.
 */
class SbaService
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout = 10;

    public function __construct()
    {
        $this->baseUrl = config('services.sba.base_url', 'https://api.socialservice.go.kr/v1');
        $this->apiKey  = config('services.sba.api_key', '');
    }

    /**
     * 산모 바우처 자격 조회
     *
     * @return array{eligible: bool, voucher_grade: ?string, self_pay_rate: ?float,
     *               total_days: ?int, total_amount: ?float}
     */
    public function inquireEligibility(PostpartumClient $client): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders($this->headers())
                ->get("{$this->baseUrl}/voucher/inquire", [
                    'phone'         => $this->decryptPhone($client->phone_encrypted),
                    'birth_date'    => $client->birth_date->format('Y-m-d'),
                    'delivery_date' => $client->delivery_date->format('Y-m-d'),
                ]);

            if (!$response->successful()) {
                Log::warning('SBA 자격 조회 실패', [
                    'client_id' => $client->id,
                    'status'    => $response->status(),
                    'body'      => $response->body(),
                ]);
                return $this->emptyEligibility();
            }

            $data = $response->json();

            return [
                'eligible'        => (bool) ($data['eligible'] ?? false),
                'voucher_grade'   => $data['grade'] ?? null,
                'self_pay_rate'   => isset($data['self_pay_rate']) ? (float) $data['self_pay_rate'] : null,
                'total_days'      => $data['total_days'] ?? null,
                'total_amount'    => isset($data['total_amount']) ? (float) $data['total_amount'] : null,
            ];
        } catch (Throwable $e) {
            Log::error('SBA 호출 예외', [
                'client_id' => $client->id,
                'error'     => $e->getMessage(),
            ]);
            return $this->emptyEligibility();
        }
    }

    // ===== Private =====

    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    private function decryptPhone(?string $encrypted): string
    {
        // 실제 구현에서는 KMS·AES-256 복호화 (Laravel Crypt)
        return decrypt($encrypted ?? '');
    }

    private function emptyEligibility(): array
    {
        return [
            'eligible'      => false,
            'voucher_grade' => null,
            'self_pay_rate' => null,
            'total_days'    => null,
            'total_amount'  => null,
        ];
    }
}

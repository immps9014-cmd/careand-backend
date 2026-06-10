<?php

namespace App\Domains\Postpartum\Services;

use App\Domains\Postpartum\Models\PostpartumClient;
use App\Domains\Postpartum\Models\VoucherTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SBA — 사회서비스 전자바우처 API 연동
 *
 * 사용 시나리오:
 *  1) inquireEligibility — 산모 자격 + 등급 + 본인부담률 조회
 *  2) useVoucher — 케어 세션 종료 시 바우처 차감
 *  3) refundVoucher — 케어 취소 시 환불
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

    /**
     * 바우처 차감 (케어 세션 종료 시)
     *
     * @return VoucherTransaction
     * @throws \DomainException 잔여 부족 또는 SBA 실패 시
     */
    public function useVoucher(
        PostpartumClient $client,
        int $careSessionId,
        float $amount,
        int $days
    ): VoucherTransaction {
        // 사전 검증: 잔여 일수
        if ($client->voucherRemainingDays() < $days) {
            throw new \DomainException(
                sprintf('바우처 잔여 일수 부족 (잔여 %d일, 요청 %d일)',
                    $client->voucherRemainingDays(), $days)
            );
        }
        if ($client->voucherRemainingAmount() < $amount) {
            throw new \DomainException(
                sprintf('바우처 잔여 금액 부족 (잔여 %s원, 요청 %s원)',
                    number_format((int) $client->voucherRemainingAmount()),
                    number_format((int) $amount))
            );
        }

        // 트랜잭션 생성
        $tx = VoucherTransaction::create([
            'postpartum_client_id' => $client->id,
            'voucher_type'         => 'mother_newborn_care',
            'transaction_type'     => 'use',
            'amount'               => $amount,
            'days'                 => $days,
            'care_session_id'      => $careSessionId,
            'transaction_date'     => now()->toDateString(),
            'status'               => 'pending',
        ]);

        // SBA 호출
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders($this->headers())
                ->post("{$this->baseUrl}/voucher/use", [
                    'client_phone'   => $this->decryptPhone($client->phone_encrypted),
                    'amount'         => $amount,
                    'days'           => $days,
                    'reference_id'   => "session-{$careSessionId}",
                    'merchant_tx_id' => "tx-{$tx->id}",
                ]);

            if (!$response->successful()) {
                $tx->update([
                    'status'        => 'failed',
                    'failed_reason' => "SBA HTTP {$response->status()}",
                    'sba_response'  => $response->json() ?? ['raw' => $response->body()],
                ]);
                throw new \DomainException(
                    "SBA API 실패 (HTTP {$response->status()}): " . $response->body()
                );
            }

            $data = $response->json();

            // 성공 시 잔여 갱신 (DB 트랜잭션)
            DB::transaction(function () use ($client, $tx, $data, $amount, $days) {
                $tx->update([
                    'status'             => 'success',
                    'sba_transaction_id' => $data['transaction_id'] ?? null,
                    'sba_response'       => $data,
                ]);

                $client->increment('voucher_used_days', $days);
                $client->increment('voucher_amount_used', $amount);
            });

            return $tx->fresh();
        } catch (\DomainException $e) {
            throw $e;
        } catch (Throwable $e) {
            $tx->update([
                'status'        => 'failed',
                'failed_reason' => $e->getMessage(),
            ]);
            Log::error('바우처 차감 예외', [
                'tx_id' => $tx->id,
                'error' => $e->getMessage(),
            ]);
            throw new \DomainException('바우처 차감 중 오류: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 바우처 환불 (취소 시)
     */
    public function refundVoucher(VoucherTransaction $original, string $reason): VoucherTransaction
    {
        if ($original->transaction_type !== 'use' || $original->status !== 'success') {
            throw new \DomainException('환불 가능한 거래가 아닙니다.');
        }

        $client = $original->postpartumClient;
        $tx = VoucherTransaction::create([
            'postpartum_client_id' => $client->id,
            'voucher_type'         => $original->voucher_type,
            'transaction_type'     => 'refund',
            'amount'               => $original->amount,
            'days'                 => $original->days,
            'care_session_id'      => $original->care_session_id,
            'transaction_date'     => now()->toDateString(),
            'status'               => 'pending',
        ]);

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders($this->headers())
                ->post("{$this->baseUrl}/voucher/refund", [
                    'original_tx_id' => $original->sba_transaction_id,
                    'merchant_tx_id' => "refund-{$tx->id}",
                    'reason'         => $reason,
                ]);

            if (!$response->successful()) {
                $tx->update(['status' => 'failed', 'failed_reason' => "SBA HTTP {$response->status()}"]);
                throw new \DomainException("환불 SBA 호출 실패: HTTP {$response->status()}");
            }

            DB::transaction(function () use ($client, $tx, $response, $original) {
                $tx->update([
                    'status'             => 'success',
                    'sba_transaction_id' => $response->json('transaction_id'),
                    'sba_response'       => $response->json(),
                ]);
                $client->decrement('voucher_used_days', $original->days);
                $client->decrement('voucher_amount_used', $original->amount);
            });

            return $tx->fresh();
        } catch (Throwable $e) {
            $tx->update(['status' => 'failed', 'failed_reason' => $e->getMessage()]);
            throw $e;
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

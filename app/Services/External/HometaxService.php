<?php

namespace App\Services\External;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 국세청 홈택스 - 사업소득 원천징수 신고
 *
 * 인력 정산 시 3.3% 원천징수 일괄 신고
 */
class HometaxService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $bizNo,
        private readonly string $apiKey,
    ) {
    }

    /**
     * 원천징수 일괄 신고
     *
     * @param array $items [['name', 'rrn', 'gross_amount', 'tax_amount'], ...]
     * @param string $period YYYY-MM
     * @return array{success: bool, filing_no: ?string, error: ?string}
     */
    public function fileWithholdingTax(array $items, string $period): array
    {
        if (config('services.external.stub')) {
            return [
                'success' => true,
                'filing_no' => 'HT-' . str_replace('-', '', $period) . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
                'error' => null,
            ];
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'X-Biz-No' => $this->bizNo,
                ])
                ->post("{$this->baseUrl}/withholding/file", [
                    'period' => $period,
                    'biz_no' => $this->bizNo,
                    'items' => $items,
                ]);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'filing_no' => null,
                    'error' => "홈택스 API 오류 ({$response->status()}): {$response->body()}",
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'filing_no' => $data['filing_no'] ?? null,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::error('홈택스 원천징수 신고 실패', [
                'period' => $period,
                'item_count' => count($items),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'filing_no' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 신고 내역 조회
     */
    public function getFilingStatus(string $filingNo): array
    {
        if (config('services.external.stub')) {
            return [
                'filing_no' => $filingNo,
                'status' => 'accepted',
                'filed_at' => now()->toIso8601String(),
            ];
        }

        $response = Http::timeout(10)
            ->withHeaders(['Authorization' => "Bearer {$this->apiKey}"])
            ->get("{$this->baseUrl}/withholding/{$filingNo}");

        if (!$response->successful()) {
            throw new RuntimeException("신고 조회 실패: {$response->status()}");
        }

        return $response->json();
    }
}

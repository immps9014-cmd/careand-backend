<?php

namespace App\Services\External;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 국민건강보험공단 (NHIS) - 장기요양보험 연동
 *
 * - 장기요양 등급 조회
 * - 월 한도액 조회
 * - 사용액/잔여액 조회
 * - 본인부담률 조회
 */
class NhisService
{
    /**
     * 2026년 기준 등급별 월 한도액 (재가급여)
     * 운영 시에는 NHIS API 또는 매년 수동 갱신 필요
     */
    private const MONTHLY_LIMITS_2026 = [
        1 => 1985200,
        2 => 1781200,
        3 => 1455800,
        4 => 1341800,
        5 => 1151600,
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {
    }

    /**
     * 어르신의 장기요양 등급 및 한도 조회
     *
     * @param string $careGradeNo 장기요양인정번호
     * @return array{grade: int, monthly_limit: int, copay_rate: int, valid_until: string}
     */
    public function getGradeInfo(string $careGradeNo): array
    {
        // 캐시 (1일)
        return Cache::remember(
            "nhis:grade:{$careGradeNo}",
            now()->addDay(),
            fn () => $this->fetchGradeInfo($careGradeNo)
        );
    }

    /**
     * 사용액 조회 (월별)
     *
     * @return array{used_amount: float, remaining_amount: float, last_updated: string}
     */
    public function getUsageStatus(string $careGradeNo, string $periodMonth): array
    {
        if (app()->environment('local', 'testing')) {
            return [
                'used_amount' => 542000,
                'remaining_amount' => 913800,
                'last_updated' => now()->toIso8601String(),
            ];
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Authorization' => "Bearer {$this->apiKey}"])
                ->get("{$this->baseUrl}/usage", [
                    'care_grade_no' => $careGradeNo,
                    'period_month' => $periodMonth,
                ]);

            if (!$response->successful()) {
                throw new RuntimeException(
                    "NHIS 사용액 조회 실패: {$response->status()}"
                );
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::error('NHIS 사용액 조회 실패', [
                'care_grade_no' => $careGradeNo,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('장기요양 사용액 조회 중 오류가 발생했습니다.');
        }
    }

    /**
     * 본인부담금 자동 분리 계산
     *
     * @param int $totalAmount 총 서비스 금액
     * @param int $careGrade 등급 (1~5)
     * @param int $copayRate 본인부담률 (%, 기본 15)
     * @return array{self_pay: int, ltc_pay: int}
     */
    public function calculateSplit(int $totalAmount, int $careGrade, int $copayRate = 15): array
    {
        $selfPay = (int) round($totalAmount * $copayRate / 100);
        $ltcPay = $totalAmount - $selfPay;

        return [
            'self_pay' => $selfPay,
            'ltc_pay' => $ltcPay,
        ];
    }

    /**
     * 등급별 월 한도액 (2026 기준)
     */
    public function getMonthlyLimit(int $careGrade): int
    {
        return self::MONTHLY_LIMITS_2026[$careGrade] ?? 0;
    }

    private function fetchGradeInfo(string $careGradeNo): array
    {
        if (app()->environment('local', 'testing')) {
            return [
                'grade' => 3,
                'monthly_limit' => self::MONTHLY_LIMITS_2026[3],
                'copay_rate' => 15,
                'valid_until' => '2026-12-31',
            ];
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Authorization' => "Bearer {$this->apiKey}"])
                ->get("{$this->baseUrl}/grade", [
                    'care_grade_no' => $careGradeNo,
                ]);

            if (!$response->successful()) {
                throw new RuntimeException("NHIS 등급 조회 실패: {$response->status()}");
            }

            $data = $response->json();
            return [
                'grade' => $data['grade'],
                'monthly_limit' => $data['monthly_limit'] ?? self::MONTHLY_LIMITS_2026[$data['grade']] ?? 0,
                'copay_rate' => $data['copay_rate'] ?? 15,
                'valid_until' => $data['valid_until'],
            ];
        } catch (\Throwable $e) {
            Log::error('NHIS 등급 조회 실패', [
                'care_grade_no' => $careGradeNo,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('장기요양 등급 조회 중 오류가 발생했습니다.');
        }
    }
}

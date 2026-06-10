<?php

namespace App\Domains\Postpartum\Services;

use App\Domains\Postpartum\Models\EpdsAssessment;
use App\Domains\Postpartum\Models\PostpartumClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * EPDS 점수 계산 + LLM 감정 분석 결합
 *
 * EPDS (Edinburgh Postnatal Depression Scale)
 *  - 10문항 0~3점, 총점 0~30
 *  - >=13: 산후우울 의심 (high risk)
 *  - >=20: 매우 높음 (critical)
 *  - q10 (자해 사고) >=1: 즉시 critical, 의료 연계 권고
 *
 * LLM 결합 (선택):
 *  - 산모 음성 일지의 LLM 감정 점수 (0~1)와 EPDS 결합 (가중치 0.7:0.3)
 *  - 결합 점수 0.7+ → high, 0.85+ → critical
 */
class EpdsCalculatorService
{
    private const EPDS_WEIGHT = 0.7;
    private const LLM_WEIGHT  = 0.3;

    /**
     * EPDS 응시 처리: 점수 계산 + 위험도 분류 + 후속 조치 결정
     *
     * @param array<string, int> $scores q1_score ~ q10_score (0~3 정수)
     */
    public function submit(PostpartumClient $client, array $scores, ?float $llmSentiment = null): EpdsAssessment
    {
        $this->validateScores($scores);

        return DB::transaction(function () use ($client, $scores, $llmSentiment) {
            // 총점 계산
            $totalScore = array_sum($scores);

            // 위험도 분류
            $riskLevel = $this->classifyRisk($totalScore, (int) $scores['q10_score']);

            // 결합 점수 (옵션)
            $combinedRisk = null;
            if ($llmSentiment !== null) {
                $combinedRisk = $this->combineRisk($totalScore, $llmSentiment);
                if ($combinedRisk >= 0.85 && $riskLevel !== 'critical') {
                    $riskLevel = 'critical';
                } elseif ($combinedRisk >= 0.7 && in_array($riskLevel, ['low', 'medium'], true)) {
                    $riskLevel = 'high';
                }
            }

            // 자동 후속 조치 결정
            $actionTaken = $this->decideAction($riskLevel, (int) $scores['q10_score']);

            $assessment = EpdsAssessment::create(array_merge(
                $scores,
                [
                    'postpartum_client_id' => $client->id,
                    'assessment_date'      => now()->toDateString(),
                    'total_score'          => $totalScore,
                    'risk_level'           => $riskLevel,
                    'llm_sentiment_score'  => $llmSentiment,
                    'combined_risk_score'  => $combinedRisk,
                    'action_taken'         => $actionTaken,
                    'action_taken_at'      => $actionTaken !== 'none' ? now() : null,
                ]
            ));

            // 고위험군이면 알림 발송
            if (in_array($riskLevel, ['high', 'critical'], true)) {
                $this->notifyHighRisk($client, $assessment);
            }

            return $assessment;
        });
    }

    /**
     * 음성 일지 → LLM 감정 분석
     *
     * @return float 0~1 (1에 가까울수록 부정적/우울 감정)
     */
    public function analyzeSentimentFromText(string $text): ?float
    {
        if (trim($text) === '') {
            return null;
        }

        try {
            $response = Http::timeout(30)
                ->post(config('services.ai.url') . '/mood/analyze', [
                    'text' => $text,
                ]);

            if (!$response->successful()) {
                return null;
            }

            return (float) ($response->json('sentiment_score') ?? 0);
        } catch (\Throwable $e) {
            Log::warning('LLM 감정 분석 실패', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 위험도 분류
     */
    public function classifyRisk(int $totalScore, int $q10Score): string
    {
        // 자해 사고는 즉시 critical
        if ($q10Score >= 1) {
            return 'critical';
        }
        if ($totalScore >= EpdsAssessment::CRITICAL_RISK_THRESHOLD) {
            return 'critical';
        }
        if ($totalScore >= EpdsAssessment::HIGH_RISK_THRESHOLD) {
            return 'high';
        }
        if ($totalScore >= 10) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * EPDS + LLM 결합 점수 계산
     *
     * @return float 0~1
     */
    private function combineRisk(int $totalScore, float $llmSentiment): float
    {
        $epdsNormalized = min(1.0, $totalScore / 30.0);
        return ($epdsNormalized * self::EPDS_WEIGHT) + ($llmSentiment * self::LLM_WEIGHT);
    }

    /**
     * 위험도별 자동 후속 조치
     */
    private function decideAction(string $riskLevel, int $q10Score): string
    {
        if ($q10Score >= 1) {
            return 'medical_referral';
        }
        if ($riskLevel === 'critical') {
            return 'medical_referral';
        }
        if ($riskLevel === 'high') {
            return 'rematch';
        }
        return 'none';
    }

    /**
     * 응답 검증
     */
    private function validateScores(array $scores): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $key = "q{$i}_score";
            if (!array_key_exists($key, $scores)) {
                throw new \InvalidArgumentException("EPDS 응답 누락: {$key}");
            }
            $v = (int) $scores[$key];
            if ($v < 0 || $v > 3) {
                throw new \InvalidArgumentException("EPDS {$key} 범위 오류 (0~3 허용, 입력 {$v})");
            }
        }
    }

    private function notifyHighRisk(PostpartumClient $client, EpdsAssessment $assessment): void
    {
        Log::warning('EPDS 고위험군 발견', [
            'client_id'  => $client->id,
            'risk_level' => $assessment->risk_level,
            'total'      => $assessment->total_score,
            'q10'        => $assessment->q10_score,
        ]);

        // 실제 운영: NotificationService 통해 본사 운영팀, 산모, 가족 보호자에게 분리 발송
        // app(NotificationService::class)->notifyEpdsHighRisk($client, $assessment);
    }
}

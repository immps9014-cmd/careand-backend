<?php

namespace App\Services\External;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * AI 마이크로서비스 (Python FastAPI) 클라이언트
 *
 * 5개 AI 모델:
 * 1. Matching (매칭 추천)
 * 2. STT (Whisper-ko)
 * 3. LLM (Claude/sLLM)
 * 4. Anomaly (이상징후 탐지)
 * 5. Forecast (수요 예측)
 */
class AiService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout = 30,
    ) {
    }

    /**
     * 1. 매칭 추천 - 어르신과 가장 잘 맞는 인력 5명 산출
     *
     * @return array{candidates: array<int, array{caregiver_id: int, score: float, reasons: array}>}
     */
    public function recommendMatch(int $requestId, array $seniorFeatures, array $caregiverPool, string $serviceDomain = 'senior', array $requiredSkills = [], float $minScore = 0.4, ?string $preferredGender = null): array
    {
        return $this->call('/ai/match/recommend', [
            'request_id' => $requestId,
            'senior' => $seniorFeatures,
            'caregivers' => $caregiverPool,
            'top_k' => 5,
            'min_score' => $minScore,
            'service_domain' => $serviceDomain,
            'required_skills' => $requiredSkills,
            'preferred_gender' => $preferredGender,
        ]);
    }

    /**
     * 1-b. 가성비 재랭킹 - 입찰가 대비 가성비를 AI 점수에 소프트 가산해 추천순 보정
     *
     * @param  array<int, array{candidate_id:int, ai_score:float, bid_hourly:float|null}>  $items
     * @return array{ranked: array<int, array{candidate_id:int, value_score:float, reason:?string}>}
     */
    public function valueRank(float $suggested, array $items): array
    {
        return $this->call('/matching/value-rank', [
            'suggested' => $suggested,
            'items' => array_values($items),
        ], 5);
    }

    /**
     * 2. STT - 음성 → 텍스트 (Whisper-ko)
     *
     * @param  array<int, string>  $diseases  대상자 질병/특이사항(옵션) — 온톨로지 기반 STT 어휘 개인화
     * @return array{stt_text: string, confidence: float, duration_sec: int, language: string}
     */
    public function transcribe(string $audioUrl, array $diseases = []): array
    {
        return $this->call('/ai/voice/transcribe', [
            'audio_url' => $audioUrl,
            'language' => 'ko',
            'diseases' => $diseases,
        ], timeout: 60);
    }

    /**
     * 3. LLM 일지 요약 - 보호자/의료진 두 톤으로 동시 생성
     *
     * @return array{guardian_version: string, medical_version: string, categorized: array, confidence: float, model: string}
     */
    public function summarizeCareLog(string $sttText, array $seniorContext): array
    {
        return $this->call('/ai/voice/summarize', [
            'stt_text' => $sttText,
            'context' => $seniorContext,
            'output_versions' => ['guardian', 'medical'],
        ], timeout: 30);
    }

    /**
     * 4. 이상징후 점수 산정
     *
     * @return array{risk_score: float, risk_type: string, severity: string, trigger_pattern: array, recommendation: array}
     */
    public function scoreAnomaly(int $seniorId, int $windowDays = 7): array
    {
        $features = $this->extractAnomalyFeatures($seniorId, $windowDays);
        return $this->call('/ai/anomaly/score', $features);
    }

    /**
     * 7일치 vital_records + health_timeseries에서 features 추출
     */
    private function extractAnomalyFeatures(int $seniorId, int $windowDays): array
    {
        $since = now()->subDays($windowDays);
        $threeDayAgo = now()->subDays(3);

        $vitals = \App\Models\VitalRecord::where('senior_id', $seniorId)
            ->where('measured_at', '>=', $since)
            ->get();

        $ts = \App\Models\HealthTimeseries::where('senior_id', $seniorId)
            ->where('recorded_at', '>=', $since)
            ->get()
            ->groupBy('metric_name');

        $features = [
            'senior_id' => $seniorId,
            'window_days' => $windowDays,
        ];

        if ($meal = $ts->get('meal_pct')) {
            $features['meal_pct_avg'] = round($meal->avg('value'), 1);
            $recent = $meal->where('recorded_at', '>=', $threeDayAgo);
            if ($recent->isNotEmpty()) {
                $features['meal_pct_3day_min'] = round($recent->avg('value'), 1);
            }
        }
        if ($sleep = $ts->get('sleep_hours')) {
            $features['sleep_hours_avg'] = round($sleep->avg('value'), 1);
        }
        if ($mood = $ts->get('mood_score')) {
            $features['mood_score_avg'] = round($mood->avg('value'), 1);
        }

        if ($vitals->isNotEmpty()) {
            $bpSys = $vitals->whereNotNull('blood_pressure_sys')->pluck('blood_pressure_sys');
            if ($bpSys->isNotEmpty()) {
                $features['bp_sys_max'] = (int) $bpSys->max();
                $features['bp_sys_min'] = (int) $bpSys->min();
            }
            $hr = $vitals->whereNotNull('heart_rate')->pluck('heart_rate');
            if ($hr->isNotEmpty()) {
                $features['heart_rate_max'] = (int) $hr->max();
                $features['heart_rate_min'] = (int) $hr->min();
            }
            $temp = $vitals->whereNotNull('body_temperature')->pluck('body_temperature');
            if ($temp->isNotEmpty()) {
                $features['body_temp_max'] = (float) $temp->max();
            }
        }

        return $features;
    }

    /**
     * 5. 챗봇 (RAG)
     *
     * @return array{answer: string, sources: array<int, array{title: string, url: string, snippet: string}>}
     */
    public function chatbotAnswer(string $question, array $context = []): array
    {
        return $this->call('/ai/chatbot/answer', [
            'question' => $question,
            'context' => $context,
        ], timeout: 30);
    }

    /**
     * 6. 수요 예측
     */
    public function forecastDemand(string $region, int $days = 7): array
    {
        return $this->call('/ai/forecast/demand', [
            'region' => $region,
            'days' => $days,
        ]);
    }

    /**
     * 공통 HTTP 호출
     */
    private function call(string $endpoint, array $payload, ?int $timeout = null): array
    {
        // 테스트 환경에서만 모의 응답 (로컬에서도 AI_SERVICE_URL이 설정되어 있으면 실제 FastAPI 호출)
        if (app()->environment('testing') || empty($this->baseUrl)) {
            return $this->mockResponse($endpoint, $payload);
        }

        $startedAt = microtime(true);

        try {
            $response = Http::timeout($timeout ?? $this->timeout)
                ->withHeaders([
                    'Authorization' => "Bearer {$this->token}",
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->baseUrl}{$endpoint}", $payload);

            $latencyMs = round((microtime(true) - $startedAt) * 1000, 2);

            if (!$response->successful()) {
                Log::warning("AI 서비스 비정상 응답", [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'latency_ms' => $latencyMs,
                ]);
                throw new RuntimeException(
                    "AI 서비스 오류 ({$response->status()}): " . substr($response->body(), 0, 500)
                );
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::error('AI 서비스 호출 실패', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("AI 서비스 연결 실패: {$e->getMessage()}");
        }
    }

    /**
     * 로컬/테스트용 모의 응답
     */
    private function mockResponse(string $endpoint, array $payload): array
    {
        return match ($endpoint) {
            '/ai/match/recommend' => $this->mockMatchRecommend($payload),
            '/matching/value-rank' => $this->mockValueRank($payload),
            '/ai/voice/transcribe' => [
                'stt_text' => '오늘 어머님 점심 반 그릇 드시고, 산책 30분 하셨고, 혈압 정상이었어요. 기분도 좋아 보이셨어요.',
                'confidence' => 0.948,
                'duration_sec' => 47,
                'language' => 'ko',
            ],
            '/ai/voice/summarize' => [
                'guardian_version' => '오늘 어머님이 점심을 평소보다 적게 드셨고(반 그릇), 식사 후 거실 산책 20분 다녀오셨습니다. 혈압은 정상 범위였고 기분도 좋아 보이셨어요.',
                'medical_version' => '식사량 50% / 운동 20분 / BP 정상 / 정서 안정. 식이 섭취 저하 관찰됨.',
                'categorized' => [
                    'meal' => ['percentage' => 50],
                    'exercise' => ['minutes' => 20, 'type' => 'walking'],
                    'vital' => ['bp' => 'normal'],
                    'mood' => 'positive',
                ],
                'confidence' => 0.93,
                'model' => 'claude-opus-4.7',
            ],
            '/ai/anomaly/score' => [
                'risk_score' => 78.0,
                'risk_type' => 'nutrition',
                'severity' => 'high',
                'trigger_pattern' => [
                    '3일 연속 식사량 50% 이하',
                    '평균 수면 5시간 미만',
                ],
                'recommendation' => [
                    '수분/영양 보충식 권장',
                    '의료진 화상 상담 권장',
                ],
            ],
            '/ai/chatbot/answer' => [
                'answer' => '장기요양 4등급 재가급여 기준 월 한도액은 1,455,800원이며, 일반 소득 기준 본인부담률은 15%로 약 218,370원입니다.',
                'sources' => [
                    ['title' => '장기요양보험 안내', 'url' => 'https://www.longtermcare.or.kr', 'snippet' => '...'],
                ],
            ],
            '/ai/forecast/demand' => [
                'region' => $payload['region'] ?? '서울',
                'forecasts' => [
                    ['date' => '2026-05-04', 'predicted_requests' => 187, 'available_caregivers' => 42],
                    ['date' => '2026-05-05', 'predicted_requests' => 152, 'available_caregivers' => 58],
                ],
            ],
            default => [],
        };
    }

    private function mockMatchRecommend(array $payload): array
    {
        $caregivers = $payload['caregivers'] ?? [];
        $candidates = [];

        foreach (array_slice($caregivers, 0, 5) as $i => $caregiver) {
            $candidates[] = [
                'caregiver_id' => $caregiver['id'] ?? ($i + 1),
                'score' => round(0.95 - $i * 0.04, 3),
                'reasons' => [
                    '치매 케어 특기',
                    '유사 경력 ' . (87 - $i * 10) . '회',
                    '근거리 ' . round(1.5 + $i * 0.6, 1) . 'km',
                ],
                'rank' => $i + 1,
            ];
        }

        return ['candidates' => $candidates];
    }

    /**
     * 가성비 재랭킹 모의 응답 — AI 서비스의 value-v1 공식을 미러링.
     */
    private function mockValueRank(array $payload): array
    {
        $suggested = (float) ($payload['suggested'] ?? 0);
        $wPrice = 0.10;
        $ranked = [];
        foreach (($payload['items'] ?? []) as $it) {
            $ai = (float) ($it['ai_score'] ?? 0);
            $bid = $it['bid_hourly'] ?? null;
            $reason = null;
            $vs = $ai;
            if ($bid !== null && $suggested > 0) {
                $vfm = max(-0.10, min(($suggested - (float) $bid) / $suggested, 0.15));
                $vs = $ai + $wPrice * $vfm;
                $reason = $vfm >= 0.05 ? '가성비 좋음' : ($vfm <= -0.05 ? '권장가 대비 높음' : null);
            }
            $ranked[] = ['candidate_id' => $it['candidate_id'] ?? null, 'value_score' => round($vs, 4), 'reason' => $reason];
        }
        usort($ranked, fn ($a, $b) => $b['value_score'] <=> $a['value_score']);

        return ['ranked' => $ranked, 'scoring_method' => 'value-v1', 'w_price' => $wPrice];
    }
}

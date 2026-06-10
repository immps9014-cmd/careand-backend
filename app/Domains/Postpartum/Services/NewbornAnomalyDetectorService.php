<?php

namespace App\Domains\Postpartum\Services;

use App\Domains\Postpartum\Models\Newborn;
use App\Domains\Postpartum\Models\NewbornAnomalyAlert;
use App\Domains\Postpartum\Models\NewbornDailyLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 신생아 발달 이상 탐지
 *
 * 4가지 위험 카테고리:
 *  - weight_loss: 체중 감소 (출생 후 10일 이내 미회복, 주당 150g 미달)
 *  - jaundice: 황달 (level 3 이상 + 14일 이상 지속)
 *  - feeding_low: 수유 부족 (8시간 무수유 / 일 6회 미만 수유)
 *  - temperature: 체온 (37.5°C 초과)
 *
 * 룰 기반 + AI 마이크로서비스 호출 결합
 */
class NewbornAnomalyDetectorService
{
    private const FEEDING_GAP_HOURS_ALERT = 8;
    private const FEEDING_MIN_PER_DAY     = 6;
    private const NO_DIAPER_HOURS_ALERT   = 24;
    private const SLEEP_MIN_HOURS         = 12;
    private const SLEEP_MAX_HOURS         = 20;
    private const FEVER_TEMP              = 37.5;
    private const JAUNDICE_LEVEL_ALERT    = 3;
    private const JAUNDICE_DAYS_ALERT     = 14;
    private const WEIGHT_BIRTH_RECOVERY_DAYS = 10;
    private const WEIGHT_GAIN_PER_WEEK_G  = 150;

    /**
     * 신생아의 최근 24시간 데이터로 이상 탐지
     */
    public function detectAnomalies(Newborn $newborn): ?NewbornAnomalyAlert
    {
        $triggers = [];
        $maxSeverity = 'low';

        // 1) 체중 검사
        if ($weightCheck = $this->checkWeight($newborn)) {
            $triggers[] = $weightCheck;
            $maxSeverity = $this->maxSeverity($maxSeverity, $weightCheck['severity']);
        }

        // 2) 황달 검사
        if ($jaundiceCheck = $this->checkJaundice($newborn)) {
            $triggers[] = $jaundiceCheck;
            $maxSeverity = $this->maxSeverity($maxSeverity, $jaundiceCheck['severity']);
        }

        // 3) 수유 검사
        if ($feedingCheck = $this->checkFeeding($newborn)) {
            $triggers[] = $feedingCheck;
            $maxSeverity = $this->maxSeverity($maxSeverity, $feedingCheck['severity']);
        }

        // 4) 체온 검사
        if ($tempCheck = $this->checkTemperature($newborn)) {
            $triggers[] = $tempCheck;
            $maxSeverity = $this->maxSeverity($maxSeverity, $tempCheck['severity']);
        }

        if (empty($triggers)) {
            return null;
        }

        // 종합 위험도 점수 (룰 점수 + AI 점수)
        $ruleScore = $this->aggregateRuleScore($triggers);
        $aiScore   = $this->fetchAiScore($newborn);
        $finalScore = $aiScore !== null
            ? min(100.0, ($ruleScore * 0.6) + ($aiScore * 0.4))
            : $ruleScore;

        // 가장 큰 위험 카테고리 선택
        usort($triggers, fn($a, $b) => $this->severityRank($b['severity']) - $this->severityRank($a['severity']));
        $primaryRiskType = $triggers[0]['risk_type'];

        return NewbornAnomalyAlert::create([
            'newborn_id'      => $newborn->id,
            'detected_at'     => now(),
            'risk_type'       => $primaryRiskType,
            'severity'        => $maxSeverity,
            'score'           => round($finalScore, 2),
            'triggers'        => $triggers,
            'recommendations' => $this->buildRecommendations($triggers),
        ]);
    }

    // ===== 개별 검사 =====

    private function checkWeight(Newborn $newborn): ?array
    {
        $latest = NewbornDailyLog::where('newborn_id', $newborn->id)
            ->where('log_type', 'weight')
            ->whereNotNull('weight_g')
            ->orderByDesc('log_datetime')
            ->first();

        if (!$latest) return null;

        $ageInDays = $newborn->ageInDays();
        $birthWeight = $newborn->birth_weight_g;

        // 출생 후 10일 이내: 출생체중 회복 여부
        if ($ageInDays >= self::WEIGHT_BIRTH_RECOVERY_DAYS && $latest->weight_g < $birthWeight) {
            return [
                'risk_type'  => 'weight_loss',
                'severity'   => 'high',
                'rule'       => sprintf('출생 후 %d일 경과, 출생체중(%dg) 미회복 (현재 %dg)',
                    self::WEIGHT_BIRTH_RECOVERY_DAYS, $birthWeight, $latest->weight_g),
                'value'      => $latest->weight_g,
                'threshold'  => $birthWeight,
            ];
        }

        // 1주일 전 체중 대비 증가 부족
        $weekAgo = NewbornDailyLog::where('newborn_id', $newborn->id)
            ->where('log_type', 'weight')
            ->whereNotNull('weight_g')
            ->where('log_datetime', '<=', now()->subDays(7))
            ->orderByDesc('log_datetime')
            ->first();

        if ($weekAgo && ($latest->weight_g - $weekAgo->weight_g) < self::WEIGHT_GAIN_PER_WEEK_G && $ageInDays > 14) {
            return [
                'risk_type'  => 'weight_loss',
                'severity'   => 'medium',
                'rule'       => sprintf('주간 체중 증가 미달 (%dg, 기준 %dg)',
                    $latest->weight_g - $weekAgo->weight_g, self::WEIGHT_GAIN_PER_WEEK_G),
                'value'      => $latest->weight_g - $weekAgo->weight_g,
                'threshold'  => self::WEIGHT_GAIN_PER_WEEK_G,
            ];
        }

        return null;
    }

    private function checkJaundice(Newborn $newborn): ?array
    {
        $latest = NewbornDailyLog::where('newborn_id', $newborn->id)
            ->where('log_type', 'jaundice')
            ->whereNotNull('jaundice_level')
            ->orderByDesc('log_datetime')
            ->first();

        if (!$latest || $latest->jaundice_level < self::JAUNDICE_LEVEL_ALERT) {
            return null;
        }

        $severity = $latest->jaundice_level >= 4 ? 'high' : 'medium';

        // 14일 이상 지속 시 critical
        if ($newborn->ageInDays() >= self::JAUNDICE_DAYS_ALERT) {
            $severity = 'critical';
        }

        return [
            'risk_type' => 'jaundice',
            'severity'  => $severity,
            'rule'      => sprintf('황달 단계 %d (생후 %d일)', $latest->jaundice_level, $newborn->ageInDays()),
            'value'     => $latest->jaundice_level,
            'threshold' => self::JAUNDICE_LEVEL_ALERT,
        ];
    }

    private function checkFeeding(Newborn $newborn): ?array
    {
        // 최근 24시간 수유 횟수
        $feedingsLast24h = NewbornDailyLog::where('newborn_id', $newborn->id)
            ->where('log_type', 'feeding')
            ->where('log_datetime', '>=', now()->subDay())
            ->count();

        if ($feedingsLast24h < self::FEEDING_MIN_PER_DAY) {
            return [
                'risk_type' => 'feeding_low',
                'severity'  => $feedingsLast24h < 4 ? 'high' : 'medium',
                'rule'      => sprintf('24시간 수유 횟수 부족 (%d회, 기준 %d회 이상)',
                    $feedingsLast24h, self::FEEDING_MIN_PER_DAY),
                'value'     => $feedingsLast24h,
                'threshold' => self::FEEDING_MIN_PER_DAY,
            ];
        }

        // 마지막 수유 후 8시간 이상
        $latestFeeding = NewbornDailyLog::where('newborn_id', $newborn->id)
            ->where('log_type', 'feeding')
            ->orderByDesc('log_datetime')
            ->first();

        if ($latestFeeding) {
            $hoursSince = $latestFeeding->log_datetime->diffInHours(now());
            if ($hoursSince >= self::FEEDING_GAP_HOURS_ALERT) {
                return [
                    'risk_type' => 'feeding_low',
                    'severity'  => $hoursSince >= 12 ? 'high' : 'medium',
                    'rule'      => sprintf('마지막 수유 후 %d시간 경과', $hoursSince),
                    'value'     => $hoursSince,
                    'threshold' => self::FEEDING_GAP_HOURS_ALERT,
                ];
            }
        }

        return null;
    }

    private function checkTemperature(Newborn $newborn): ?array
    {
        $latest = NewbornDailyLog::where('newborn_id', $newborn->id)
            ->where('log_type', 'temperature')
            ->whereNotNull('body_temperature')
            ->orderByDesc('log_datetime')
            ->first();

        if (!$latest || (float) $latest->body_temperature <= self::FEVER_TEMP) {
            return null;
        }

        $temp = (float) $latest->body_temperature;
        $severity = $temp >= 38.5 ? 'critical' : ($temp >= 38.0 ? 'high' : 'medium');

        return [
            'risk_type' => 'temperature',
            'severity'  => $severity,
            'rule'      => sprintf('체온 %.1f°C (기준 %.1f°C)', $temp, self::FEVER_TEMP),
            'value'     => $temp,
            'threshold' => self::FEVER_TEMP,
        ];
    }

    // ===== AI 마이크로서비스 호출 =====

    private function fetchAiScore(Newborn $newborn): ?float
    {
        try {
            $response = Http::timeout(10)
                ->post(config('services.ai.url') . '/newborn/score', [
                    'newborn_id' => $newborn->id,
                ]);

            if (!$response->successful()) {
                return null;
            }
            return (float) $response->json('score');
        } catch (\Throwable $e) {
            Log::warning('AI 신생아 점수 조회 실패', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ===== 보조 =====

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            'critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1, default => 0,
        };
    }

    private function maxSeverity(string $a, string $b): string
    {
        return $this->severityRank($a) >= $this->severityRank($b) ? $a : $b;
    }

    private function aggregateRuleScore(array $triggers): float
    {
        $weights = ['critical' => 100, 'high' => 70, 'medium' => 40, 'low' => 15];
        $sum = 0;
        foreach ($triggers as $t) {
            $sum += $weights[$t['severity']] ?? 0;
        }
        return min(100.0, $sum);
    }

    private function buildRecommendations(array $triggers): array
    {
        $recommendations = [];
        foreach ($triggers as $t) {
            $recommendations[] = match ($t['risk_type']) {
                'weight_loss' => '소아과 외래 진료 권고. 수유 빈도/양 증가, 체중 매일 측정.',
                'jaundice'    => '햇빛 노출 (간접광), 수유 빈도 증가. 단계 4 이상은 즉시 의료기관.',
                'feeding_low' => '수유 빈도 점검. 모유 부족 시 분유 보충, 수유 자세 재교육.',
                'temperature' => '체온 38°C 이상 시 즉시 소아과. 옷 한 겹 벗기고 미온수 닦기.',
                default       => '담당 산후관리사와 상의 권고.',
            };
        }
        return array_values(array_unique($recommendations));
    }
}

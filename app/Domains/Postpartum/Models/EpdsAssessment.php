<?php

namespace App\Domains\Postpartum\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EPDS — Edinburgh Postnatal Depression Scale
 *
 * 10문항 0~3점, 총 0~30점
 * - 13점 이상: 산후우울 의심 → 알림
 * - q10 (자해 사고) 1점 이상: 즉시 본사 알림 + 의료 연계
 */
class EpdsAssessment extends Model
{
    use HasFactory;

    protected $table = 'epds_assessments';

    public $timestamps = false;
    protected $dates = ['assessment_date', 'action_taken_at', 'created_at'];

    protected $fillable = [
        'postpartum_client_id', 'assessment_date',
        'q1_score', 'q2_score', 'q3_score', 'q4_score', 'q5_score',
        'q6_score', 'q7_score', 'q8_score', 'q9_score', 'q10_score',
        'total_score', 'risk_level',
        'llm_sentiment_score', 'combined_risk_score',
        'action_taken', 'action_taken_at', 'action_taken_by',
    ];

    protected $casts = [
        'assessment_date'      => 'date',
        'action_taken_at'      => 'datetime',
        'created_at'           => 'datetime',
        'llm_sentiment_score'  => 'decimal:4',
        'combined_risk_score'  => 'decimal:4',
    ];

    public const HIGH_RISK_THRESHOLD     = 13;
    public const CRITICAL_RISK_THRESHOLD = 20;

    public function postpartumClient(): BelongsTo
    {
        return $this->belongsTo(PostpartumClient::class);
    }

    /**
     * EPDS 점수 합계 계산
     */
    public function calculateTotalScore(): int
    {
        $total = 0;
        for ($i = 1; $i <= 10; $i++) {
            $total += (int) $this->{"q{$i}_score"};
        }
        return $total;
    }

    /**
     * 위험도 분류
     */
    public function calculateRiskLevel(): string
    {
        // 자해 사고 (q10) 1점 이상은 즉시 critical
        if ((int) $this->q10_score >= 1) {
            return 'critical';
        }

        $score = $this->total_score ?? $this->calculateTotalScore();

        if ($score >= self::CRITICAL_RISK_THRESHOLD) {
            return 'critical';
        }
        if ($score >= self::HIGH_RISK_THRESHOLD) {
            return 'high';
        }
        if ($score >= 10) {
            return 'medium';
        }
        return 'low';
    }

    public function isHighRisk(): bool
    {
        return in_array($this->risk_level, ['high', 'critical'], true);
    }

    public function requiresImmediateAction(): bool
    {
        return $this->risk_level === 'critical' || (int) $this->q10_score >= 1;
    }
}

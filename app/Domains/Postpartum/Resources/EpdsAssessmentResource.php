<?php

namespace App\Domains\Postpartum\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EpdsAssessmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'postpartum_client_id' => $this->postpartum_client_id,
            'assessment_date'      => $this->assessment_date?->toDateString(),

            'scores' => [
                'q1' => $this->q1_score,
                'q2' => $this->q2_score,
                'q3' => $this->q3_score,
                'q4' => $this->q4_score,
                'q5' => $this->q5_score,
                'q6' => $this->q6_score,
                'q7' => $this->q7_score,
                'q8' => $this->q8_score,
                'q9' => $this->q9_score,
                'q10' => $this->q10_score, // 자해 사고 항목
            ],

            'total_score'           => $this->total_score,
            'risk_level'            => $this->risk_level,
            'is_self_harm_risk'     => $this->q10_score >= 1,

            'llm_sentiment_score'   => $this->llm_sentiment_score
                ? (float) $this->llm_sentiment_score : null,
            'combined_risk_score'   => $this->combined_risk_score
                ? (float) $this->combined_risk_score : null,

            'action' => [
                'taken'    => $this->action_taken,
                'taken_at' => $this->action_taken_at?->toIso8601String(),
                'taken_by' => $this->action_taken_by,
            ],

            'created_at'            => $this->created_at?->toIso8601String(),
        ];
    }
}

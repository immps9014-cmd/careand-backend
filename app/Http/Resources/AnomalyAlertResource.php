<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnomalyAlertResource extends JsonResource
{
    private const RISK_TYPE_KO = [
        'fall' => '낙상',
        'delirium' => '섬망',
        'depression' => '우울',
        'nutrition' => '영양',
        'other' => '기타',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'senior_id' => $this->senior_id,
            'risk_type' => $this->risk_type,
            'risk_type_ko' => self::RISK_TYPE_KO[$this->risk_type] ?? $this->risk_type,
            'risk_score' => (float) $this->risk_score,
            'severity' => $this->severity,
            'severity_ko' => match ($this->severity) {
                'low' => '낮음',
                'mid' => '중간',
                'high' => '높음',
                'critical' => '긴급',
                default => $this->severity,
            },
            'trigger_pattern' => $this->trigger_pattern,
            'recommendation' => $this->recommendation,
            'status' => $this->status,
            'status_ko' => match ($this->status) {
                'new' => '신규',
                'acknowledged' => '확인됨',
                'in_progress' => '처리중',
                'resolved' => '해결됨',
                'dismissed' => '무시됨',
                default => $this->status,
            },
            'resolution_note' => $this->resolution_note,
            'detected_at' => $this->detected_at?->toIso8601String(),
            'detected_ago' => $this->detected_at?->diffForHumans(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),

            'senior' => $this->whenLoaded('senior', fn () => [
                'id' => $this->senior->id,
                'name' => $this->senior->name,
                'care_grade' => $this->senior->care_grade,
            ]),

            'resolver' => $this->whenLoaded('resolver', fn () => [
                'id' => $this->resolver?->id,
                'name' => $this->resolver?->name,
            ]),
        ];
    }
}

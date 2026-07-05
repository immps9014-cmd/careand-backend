<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MatchRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_domain' => $this->service_domain,
            'mode' => $this->mode,
            'status' => $this->status,
            'scheduled_start' => $this->scheduled_start?->toIso8601String(),
            'duration_min' => $this->duration_min,
            'special_request' => $this->special_request,
            'matched_at' => $this->matched_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'requirements' => $this->requirements,
            'price_estimate' => $this->price_estimate,
            'budget_hourly' => $this->budget_hourly !== null ? (float) $this->budget_hourly : null,

            'senior' => $this->whenLoaded('senior', fn () => $this->senior ? [
                'id' => $this->senior->id,
                'name' => $this->senior->name,
                'care_grade' => $this->senior->care_grade,
            ] : null),

            'nursing_patient' => $this->whenLoaded('nursingPatient', fn () => $this->nursingPatient ? [
                'id' => $this->nursingPatient->id,
                'name' => $this->nursingPatient->name,
                'hospital_name' => $this->nursingPatient->hospital_name,
            ] : null),

            'service_address' => $this->whenLoaded('serviceAddress', fn () => $this->serviceAddress ? [
                'id' => $this->serviceAddress->id,
                'label' => $this->serviceAddress->label,
                'address' => $this->serviceAddress->address,
            ] : null),

            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'base_rate' => (float) $this->category->base_rate,
            ]),

            // 확정 매칭 정보 — 매칭완료 카드에 케어자 이름·케어 일정·결제 상태 노출
            'match' => $this->whenLoaded('match', fn () => $this->match ? [
                'id' => $this->match->id,
                'status' => $this->match->status, // 케어 진행: confirmed|in_progress|completed|cancelled|no_show
                'scheduled_start' => $this->match->scheduled_start?->toIso8601String(),
                'scheduled_end' => $this->match->scheduled_end?->toIso8601String(),
                'caregiver_name' => $this->match->caregiver?->user?->name,
                'payment_status' => $this->match->payment?->status,
            ] : null),
        ];
    }
}

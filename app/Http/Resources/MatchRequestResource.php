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
        ];
    }
}

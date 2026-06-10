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
            'mode' => $this->mode,
            'status' => $this->status,
            'scheduled_start' => $this->scheduled_start?->toIso8601String(),
            'duration_min' => $this->duration_min,
            'special_request' => $this->special_request,
            'matched_at' => $this->matched_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'senior' => $this->whenLoaded('senior', fn () => [
                'id' => $this->senior->id,
                'name' => $this->senior->name,
                'care_grade' => $this->senior->care_grade,
            ]),

            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'base_rate' => (float) $this->category->base_rate,
            ]),
        ];
    }
}

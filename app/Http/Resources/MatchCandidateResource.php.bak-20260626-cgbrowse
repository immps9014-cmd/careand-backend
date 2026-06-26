<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MatchCandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rank' => $this->rank,
            'ai_score' => (float) $this->ai_score,
            'ai_reasons' => $this->ai_reasons,
            'response' => $this->response,
            'responded_at' => $this->responded_at?->toIso8601String(),

            'caregiver' => $this->whenLoaded('caregiver', fn () => [
                'id' => $this->caregiver->id,
                'name' => $this->caregiver->user->name ?? null,
                'gender' => $this->caregiver->gender,
                'age' => $this->caregiver->birth_date ? $this->caregiver->birth_date->age : null,
                'specialties' => $this->caregiver->specialties,
                'rating_avg' => (float) $this->caregiver->rating_avg,
                'completed_sessions' => $this->caregiver->completed_sessions,
                'organization' => $this->caregiver->organization?->only(['id', 'name']),
            ]),
        ];
    }
}

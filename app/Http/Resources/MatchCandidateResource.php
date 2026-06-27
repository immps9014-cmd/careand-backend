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
            // ai=시스템 추천, self=돌봄전문가 직접 지원
            'source' => $this->source ?? 'ai',
            'response' => $this->response,
            'responded_at' => $this->responded_at?->toIso8601String(),

            // 역경매 입찰
            'bid_hourly' => $this->bid_hourly !== null ? (float) $this->bid_hourly : null,
            'bid_note' => $this->bid_note,
            'bid_status' => $this->bid_status,
            'bid_at' => $this->bid_at?->toIso8601String(),

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

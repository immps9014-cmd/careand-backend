<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CareSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // 동행 전체 경로(정확 주소 포함) — 확정 매칭 세션을 보는 당사자에게만 노출.
        // 만남=서비스 주소 정확값, 방문/복귀/경유지/이동수단.
        $companionRoute = null;
        if ($this->relationLoaded('match') && $this->match && $this->match->request) {
            $req = $this->match->request;
            $route = is_array($req->requirements ?? null) ? ($req->requirements['companion_route'] ?? null) : null;
            if (is_array($route)) {
                $returnToOrigin = (bool) ($route['return_to_origin'] ?? true);
                $companionRoute = [
                    'meeting' => $req->serviceAddress->address ?? null,
                    'destination' => $route['destination'] ?? null,
                    'return_to_origin' => $returnToOrigin,
                    'return_address' => $returnToOrigin ? ($req->serviceAddress->address ?? null) : ($route['return_address'] ?? null),
                    'waypoints' => array_values(array_filter((array) ($route['waypoints'] ?? []))),
                    'transport' => in_array($route['transport'] ?? null, ['taxi', 'transit'], true) ? $route['transport'] : null,
                ];
            }
        }

        return [
            'id' => $this->id,
            'status' => $this->status,
            'actual_start' => $this->actual_start?->toIso8601String(),
            'actual_end' => $this->actual_end?->toIso8601String(),
            'duration_min' => $this->duration_min,
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at?->toIso8601String(),

            'match' => $this->whenLoaded('match', fn () => [
                'id' => $this->match->id,
                'hourly_rate' => (float) $this->match->hourly_rate,
                'estimated_amount' => (float) $this->match->estimated_amount,
                'caregiver' => [
                    'id' => $this->match->caregiver->id ?? null,
                    'name' => $this->match->caregiver->user->name ?? null,
                ],
                'companion_route' => $companionRoute,
                'senior' => [
                    'id' => $this->match->request->senior->id ?? null,
                    'name' => $this->match->request->senior->name ?? null,
                ],
            ]),

            'attendance' => $this->whenLoaded('attendanceLogs', fn () => [
                'checkin' => $this->attendanceLogs->where('event_type', 'checkin')->first(),
                'checkout' => $this->attendanceLogs->where('event_type', 'checkout')->first(),
            ]),

            'activities' => $this->whenLoaded('activities'),
            'photos' => $this->whenLoaded('photos'),

            'voice_logs' => $this->whenLoaded('voiceLogs', fn () => $this->voiceLogs->map(fn ($v) => [
                'id' => $v->id,
                'status' => $v->status,
                'duration_sec' => $v->duration_sec,
                'stt_text' => $v->stt_text,
                'created_at' => $v->created_at->toIso8601String(),
            ])),

            'ai_summary' => $this->whenLoaded('aiSummaries', fn () => $this->aiSummaries->first() ? [
                'guardian_version' => $this->aiSummaries->first()->guardian_version,
                'medical_version' => $this->aiSummaries->first()->medical_version,
                'categorized' => $this->aiSummaries->first()->categorized,
                'confidence' => (float) $this->aiSummaries->first()->confidence,
            ] : null),
        ];
    }
}

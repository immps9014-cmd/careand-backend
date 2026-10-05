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
            // 가성비 재랭킹(입찰 반영, 보호자 조회 시점 산정) — 미산정 시 null
            'value_score' => isset($this->value_score) ? (float) $this->value_score : null,
            'value_reason' => $this->value_reason ?? null,

            'caregiver' => $this->whenLoaded('caregiver', fn () => [
                'id' => $this->caregiver->id,
                'name' => $this->caregiver->user->name ?? null,
                'gender' => $this->caregiver->gender,
                'age' => $this->caregiver->birth_date ? $this->caregiver->birth_date->age : null,
                'specialties' => $this->caregiver->specialties,
                'rating_avg' => (float) $this->caregiver->rating_avg,
                'rating_count' => (int) $this->caregiver->rating_count,   // 0 이면 화면은 「신규」
                'completed_sessions' => $this->caregiver->completed_sessions,
                'organization' => $this->caregiver->organization?->only(['id', 'name']),
                // 후보 카드에서 바로 — 자격 확인·활동 지역(시·군·구까지)·사진(2026-10-05)
                'license_verified' => $this->caregiver->license_verified_at !== null,
                'region' => self::region($this->caregiver->base_address),
                'photo_url' => \App\Support\CaregiverProfileExtras::photoUrl((int) $this->caregiver->id, $this->caregiver->photo_path, $this->caregiver->photo_updated_at),
                'verified_doc_count' => count(app(\App\Services\CaregiverDocumentService::class)->publicSummary((int) $this->caregiver->id)),
            ]),
        ];
    }

    /** 「대전광역시 서구 둔산동 …」 → 「대전 서구」 — 번지·동은 빼고 시·군·구까지만 */
    public static function region(?string $addr): ?string
    {
        $w = preg_split('/\s+/', trim((string) $addr)) ?: [];
        if (count($w) < 2) {
            return $w[0] ?? null ?: null;
        }
        $short = ['충청남도' => '충남', '충청북도' => '충북', '전라남도' => '전남', '전라북도' => '전북', '전북특별자치도' => '전북',
            '경상남도' => '경남', '경상북도' => '경북', '강원도' => '강원', '강원특별자치도' => '강원', '제주특별자치도' => '제주',
            '경기도' => '경기', '세종특별자치시' => '세종'];
        $city = $short[$w[0]] ?? preg_replace('/(특별시|광역시)$/u', '', $w[0]);

        return trim($city . ' ' . $w[1]);
    }
}

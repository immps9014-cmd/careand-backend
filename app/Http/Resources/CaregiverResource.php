<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CaregiverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user->name ?? null,
            'gender' => $this->gender,
            'age' => $this->birth_date ? $this->birth_date->age : null,
            'license_no' => $this->maskLicenseNo($this->license_no),
            'license_verified' => $this->license_verified_at !== null,
            // 케어앤에듀 인증 돌봄전문가 마크·자격 정보(2026-10-07)
            'careand_certified' => ($cert = app(\App\Services\CareandCertService::class)->summary((int) $this->user_id)) !== null,
            'careand_cert' => $cert,
            'specialties' => $this->specialties,
            'service_domains' => $this->service_domains,
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'completed_sessions' => $this->completed_sessions,
            'grade_level' => $this->grade_level,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'base_address' => $this->base_address,
            'base_lat' => $this->base_lat,
            'base_lng' => $this->base_lng,
            'default_rate' => $this->default_rate !== null ? (float) $this->default_rate : null,
            'auto_bid' => (bool) $this->auto_bid,
            // 프로필 사진 — 서명 링크(6시간). 이 리소스는 로그인 회원 응답에만 쓰인다
            'photo_url' => \App\Support\CaregiverProfileExtras::photoUrl((int) $this->id, $this->photo_path, $this->photo_updated_at),

            'organization' => $this->whenLoaded('organization', fn () => $this->organization ? [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
            ] : null),
        ];
    }

    private function maskLicenseNo(?string $no): ?string
    {
        if (!$no) return null;
        if (strlen($no) <= 4) return $no;
        return substr($no, 0, 4) . str_repeat('*', strlen($no) - 4);
    }
}

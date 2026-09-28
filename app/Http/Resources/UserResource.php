<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'phone' => $this->phone,
            'name' => $this->name,
            'role' => $this->role,
            'status' => $this->status,
            'phone_verified' => $this->phone_verified_at !== null,
            'email_verified' => $this->email_verified_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),

            // 역할별 프로필 (load 시에만 포함)
            'guardian' => $this->whenLoaded('guardian'),
            'caregiver' => $this->whenLoaded('caregiver'),
            'organization' => $this->whenLoaded('organization'),
            'admin' => $this->whenLoaded('admin'),
            // 관리자 권한 5단계 — 화면 메뉴 표시용 {영역: {read, write}} (S2-3). 실제 차단은 서버 AdminPermission
            'admin_permissions' => $this->when($this->role === 'admin' && $this->relationLoaded('admin'),
                fn () => \App\Support\AdminRbac::permissionsFor($this->admin?->permission_level)),
            'admin_level_label' => $this->when($this->role === 'admin' && $this->relationLoaded('admin'),
                fn () => config('admin_rbac.levels.' . $this->admin?->permission_level)),
        ];
    }
}

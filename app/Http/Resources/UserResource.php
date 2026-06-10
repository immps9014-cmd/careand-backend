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
        ];
    }
}

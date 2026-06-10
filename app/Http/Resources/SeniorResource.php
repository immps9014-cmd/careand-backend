<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeniorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'age' => $this->birth_date ? $this->birth_date->age : null,
            'birth_date' => $this->birth_date?->toDateString(),
            'gender' => $this->gender,
            'care_grade' => $this->care_grade,
            'care_grade_no' => $this->care_grade_no,
            'diseases' => $this->diseases,
            'special_notes' => $this->special_notes,
            'home_address' => $this->home_address,
            'home_lat' => $this->home_lat,
            'home_lng' => $this->home_lng,
            'created_at' => $this->created_at?->toIso8601String(),

            'guardian' => $this->whenLoaded('guardian', fn () => [
                'id' => $this->guardian->id,
                'name' => $this->guardian->user->name ?? null,
                'relation' => $this->guardian->relation,
            ]),

            'current_voucher' => $this->whenLoaded('ltcVouchers', fn () => $this->ltcVouchers
                ->where('period_month', now()->startOfMonth()->toDateString())
                ->first()),
        ];
    }
}

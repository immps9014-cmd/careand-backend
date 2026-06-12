<?php

namespace App\Domains\Nursing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NursingPatientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'birth_date' => $this->birth_date?->toDateString(),
            'gender' => $this->gender,
            'hospital_name' => $this->hospital_name,
            'hospital_address' => $this->hospital_address,
            'hospital_lat' => $this->hospital_lat !== null ? (float) $this->hospital_lat : null,
            'hospital_lng' => $this->hospital_lng !== null ? (float) $this->hospital_lng : null,
            'ward_room' => $this->ward_room,
            'mobility' => $this->mobility,
            'diseases' => $this->diseases ?? [],
            'care_requirements' => $this->care_requirements ?? [],
            'special_notes' => $this->special_notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

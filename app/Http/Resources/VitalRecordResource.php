<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VitalRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'senior_id' => $this->senior_id,
            'session_id' => $this->session_id,
            'blood_pressure' => $this->blood_pressure_sys && $this->blood_pressure_dia
                ? "{$this->blood_pressure_sys}/{$this->blood_pressure_dia}"
                : null,
            'blood_pressure_sys' => $this->blood_pressure_sys,
            'blood_pressure_dia' => $this->blood_pressure_dia,
            'blood_sugar' => $this->blood_sugar,
            'body_temperature' => $this->body_temperature ? (float) $this->body_temperature : null,
            'heart_rate' => $this->heart_rate,
            'weight' => $this->weight ? (float) $this->weight : null,
            'measured_at' => $this->measured_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

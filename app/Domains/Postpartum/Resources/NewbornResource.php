<?php

namespace App\Domains\Postpartum\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NewbornResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'postpartum_client_id'  => $this->postpartum_client_id,
            'name'                  => $this->name,
            'gender'                => $this->gender,
            'birth_datetime'        => $this->birth_datetime?->toIso8601String(),
            'birth_weight_g'        => $this->birth_weight_g,
            'birth_height_cm'       => $this->birth_height_cm ? (float) $this->birth_height_cm : null,
            'gestational_age' => [
                'weeks' => $this->gestational_age_weeks,
                'days'  => $this->gestational_age_days,
            ],
            'birth_order'           => $this->birth_order,
            'apgar' => [
                'one_min'  => $this->apgar_1min,
                'five_min' => $this->apgar_5min,
            ],
            'nicu_days'             => $this->nicu_days,
            'special_conditions'    => $this->special_conditions,
            'is_alive'              => (bool) $this->is_alive,
            'age_in_days'           => method_exists($this->resource, 'ageInDays') ? $this->ageInDays() : null,
            'created_at'            => $this->created_at?->toIso8601String(),
        ];
    }
}

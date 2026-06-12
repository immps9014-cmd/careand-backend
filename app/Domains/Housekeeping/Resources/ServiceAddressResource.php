<?php

namespace App\Domains\Housekeeping\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceAddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'address' => $this->address,
            'lat' => $this->lat !== null ? (float) $this->lat : null,
            'lng' => $this->lng !== null ? (float) $this->lng : null,
            'dwelling_type' => $this->dwelling_type,
            'size_m2' => $this->size_m2 !== null ? (int) $this->size_m2 : null,
            'has_pets' => (bool) $this->has_pets,
            'entry_note' => $this->entry_note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

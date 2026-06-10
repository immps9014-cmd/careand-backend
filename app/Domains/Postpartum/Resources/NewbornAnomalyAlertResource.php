<?php

namespace App\Domains\Postpartum\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NewbornAnomalyAlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'newborn_id'      => $this->newborn_id,
            'detected_at'     => $this->detected_at?->toIso8601String(),
            'risk_type'       => $this->risk_type,
            'severity'        => $this->severity,
            'score'           => $this->score ? (float) $this->score : null,
            'triggers'        => $this->triggers,
            'recommendations' => $this->recommendations,

            'notification' => [
                'notified_at' => $this->notified_at?->toIso8601String(),
            ],

            'resolution' => [
                'resolved'    => $this->resolved_at !== null,
                'resolved_at' => $this->resolved_at?->toIso8601String(),
                'resolved_by' => $this->resolved_by,
                'note'        => $this->resolution_note,
            ],
        ];
    }
}

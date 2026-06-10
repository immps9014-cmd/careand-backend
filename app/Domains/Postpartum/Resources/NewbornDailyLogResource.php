<?php

namespace App\Domains\Postpartum\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NewbornDailyLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'newborn_id'      => $this->newborn_id,
            'care_session_id' => $this->care_session_id,
            'log_datetime'    => $this->log_datetime?->toIso8601String(),
            'log_type'        => $this->log_type,
            'feeding' => $this->when(
                $this->log_type === 'feeding',
                fn() => [
                    'type'         => $this->feeding_type,
                    'volume_ml'    => $this->feeding_volume_ml,
                    'duration_min' => $this->feeding_duration_min,
                ]
            ),
            'diaper' => $this->when(
                $this->log_type === 'diaper',
                fn() => [
                    'type'         => $this->diaper_type,
                    'stool_color'  => $this->stool_color,
                ]
            ),
            'sleep' => $this->when(
                $this->log_type === 'sleep',
                fn() => [
                    'start' => $this->sleep_start?->toIso8601String(),
                    'end'   => $this->sleep_end?->toIso8601String(),
                ]
            ),
            'weight_g'        => $this->when($this->log_type === 'weight', $this->weight_g),
            'jaundice_level'  => $this->when($this->log_type === 'jaundice', $this->jaundice_level),
            'body_temperature'=> $this->when(
                $this->log_type === 'temperature',
                fn() => $this->body_temperature ? (float) $this->body_temperature : null
            ),
            'note_text'       => $this->note_text,
            'note_audio_url'  => $this->note_audio_url,
            'is_anomaly'      => (bool) $this->is_anomaly,
            'anomaly_score'   => $this->anomaly_score ? (float) $this->anomaly_score : null,
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}

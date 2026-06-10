<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'gross_amount' => (float) $this->gross_amount,
            'withholding_tax' => (float) $this->withholding_tax_3_3,
            'net_amount' => (float) $this->net_amount,
            'hometax_filing_no' => $this->hometax_filing_no,
            'status' => $this->status,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'session_id' => $item->session_id,
                'date' => $item->session->actual_end->toDateString() ?? null,
                'senior_name' => $item->session->match->request->senior->name ?? null,
                'hours' => (float) $item->hours,
                'hourly_rate' => (float) $item->hourly_rate,
                'amount' => (float) $item->amount,
                'surcharge' => (float) $item->surcharge,
            ])),
        ];
    }
}

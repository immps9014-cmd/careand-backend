<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'total_amount' => (float) $this->total_amount,
            'amount_self_pay' => (float) $this->amount_self_pay,
            'amount_ltc_pay' => (float) $this->amount_ltc_pay,
            'method' => $this->method,
            'status' => $this->status,
            'pg_provider' => $this->pg_provider,
            'pg_tid' => $this->pg_tid,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'match' => $this->whenLoaded('match', fn () => [
                'id' => $this->match->id,
                'senior_name' => $this->match->request->senior->name ?? null,
            ]),

            'items' => $this->whenLoaded('items'),
        ];
    }
}

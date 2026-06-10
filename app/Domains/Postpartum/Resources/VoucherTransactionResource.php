<?php

namespace App\Domains\Postpartum\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VoucherTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'postpartum_client_id' => $this->postpartum_client_id,
            'voucher_type'         => $this->voucher_type,
            'transaction_type'     => $this->transaction_type,
            'amount'               => $this->amount ? (float) $this->amount : 0,
            'days'                 => $this->days,
            'care_session_id'      => $this->care_session_id,
            'transaction_date'     => $this->transaction_date?->toDateString(),
            'sba_transaction_id'   => $this->sba_transaction_id,
            'status'               => $this->status,
            'failed_reason'        => $this->failed_reason,
            'created_at'           => $this->created_at?->toIso8601String(),
            // sba_response 원문은 보안상 일반 사용자에게 미노출
            'sba_response'         => $this->when(
                $request->user()?->hasAnyRole(['super_admin', 'hq_operator']),
                $this->sba_response
            ),
        ];
    }
}

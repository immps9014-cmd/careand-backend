<?php

namespace App\Domains\Postpartum\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostpartumClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'user_id'         => $this->user_id,
            'name'            => $this->name,
            'birth_date'      => $this->birth_date?->toDateString(),
            'address'         => $this->address,
            'address_detail'  => $this->address_detail,
            'region_code'     => $this->region_code,
            'branch'          => $this->whenLoaded('branch', fn() => [
                'id'   => $this->branch->id,
                'code' => $this->branch->code,
                'name' => $this->branch->name,
            ]),

            'delivery' => [
                'date'                  => $this->delivery_date?->toDateString(),
                'type'                  => $this->delivery_type,
                'is_first_baby'         => $this->is_first_baby,
                'is_multiple_birth'     => $this->is_multiple_birth,
                'breastfeeding_intent'  => $this->breastfeeding_intent,
                'days_since_delivery'   => $this->daysSinceDelivery(),
            ],

            'pregnancy_complications' => $this->pregnancy_complications,
            'postpartum_conditions'   => $this->postpartum_conditions,
            'medications'             => $this->medications,

            'voucher' => [
                'grade'           => $this->voucher_grade,
                'self_pay_rate'   => $this->voucher_self_pay_rate ? (float) $this->voucher_self_pay_rate : null,
                'total_days'      => $this->voucher_total_days,
                'used_days'       => $this->voucher_used_days,
                'remaining_days'  => $this->voucherRemainingDays(),
                'total_amount'    => $this->voucher_amount_total,
                'used_amount'     => $this->voucher_amount_used,
                'remaining_amount'=> $this->voucherRemainingAmount(),
                'certified_at'    => $this->voucher_certified_at?->toDateString(),
                'eligible'        => $this->isVoucherEligible(),
            ],

            'newborns' => $this->whenLoaded('newborns', fn() => $this->newborns->map(fn($nb) => [
                'id'             => $nb->id,
                'name'           => $nb->name,
                'gender'         => $nb->gender,
                'birth_datetime' => $nb->birth_datetime?->toIso8601String(),
                'birth_weight_g' => $nb->birth_weight_g,
                'age_in_days'    => $nb->ageInDays(),
            ])),

            'recent_epds' => $this->whenLoaded('epdsAssessments', fn() => $this->epdsAssessments->take(3)->map(fn($a) => [
                'date'       => $a->assessment_date->toDateString(),
                'total'      => $a->total_score,
                'risk_level' => $a->risk_level,
            ])),

            'status'          => $this->status,
            'special_notes'   => $this->special_notes,
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }
}

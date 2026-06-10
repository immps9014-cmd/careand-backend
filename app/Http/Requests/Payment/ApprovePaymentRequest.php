<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class ApprovePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->guardian !== null;
    }

    public function rules(): array
    {
        return [
            'match_id' => ['required', 'exists:matches,id'],
            'method' => ['required', 'in:card,account,voucher_only'],
            'card_token' => ['required_if:method,card', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'card_token.required_if' => '카드 결제 시 card_token이 필요합니다.',
        ];
    }
}

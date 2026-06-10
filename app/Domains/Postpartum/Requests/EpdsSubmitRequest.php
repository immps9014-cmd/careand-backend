<?php

namespace App\Domains\Postpartum\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EpdsSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $questionRules = [];
        for ($i = 1; $i <= 10; $i++) {
            $questionRules["q{$i}_score"] = ['required', 'integer', 'min:0', 'max:3'];
        }

        return array_merge([
            'postpartum_client_id'   => ['required', 'integer', Rule::exists('postpartum_clients', 'id')],
            'llm_sentiment_text'     => ['nullable', 'string', 'max:10000', 'min:20'],
        ], $questionRules);
    }

    public function messages(): array
    {
        return [
            '*.required' => 'EPDS 모든 문항 응답이 필요합니다.',
            '*.between'  => 'EPDS 점수는 0~3 범위여야 합니다.',
        ];
    }
}

<?php

namespace App\Domains\Postpartum\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PostpartumClientStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name'                    => ['required', 'string', 'max:50'],
            'phone'                   => ['required', 'string', 'max:20'],
            'birth_date'              => ['required', 'date', 'before:today'],
            'address'                 => ['required', 'string', 'max:500'],
            'address_detail'          => ['nullable', 'string', 'max:200'],
            'region_code'             => ['required', 'string', 'max:20'],
            'branch_id'               => ['nullable', 'integer', Rule::exists('branches', 'id')],

            'delivery_date'           => ['required', 'date'],
            'delivery_type'           => ['required', Rule::in(['natural', 'cesarean', 'vbac'])],
            'is_first_baby'           => ['boolean'],
            'is_multiple_birth'       => ['boolean'],
            'breastfeeding_intent'    => ['nullable', Rule::in(['exclusive', 'mixed', 'formula', 'undecided'])],

            'pregnancy_complications' => ['nullable', 'array'],
            'postpartum_conditions'   => ['nullable', 'array'],
            'medications'             => ['nullable', 'array'],

            'special_notes'           => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'delivery_type.in'   => '출산 유형은 자연분만, 제왕절개, VBAC 중 하나여야 합니다.',
            'birth_date.before'  => '생년월일은 오늘 이전이어야 합니다.',
        ];
    }
}

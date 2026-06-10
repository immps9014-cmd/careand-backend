<?php

namespace App\Http\Requests\Senior;

use Illuminate\Foundation\Http\FormRequest;

class StoreSeniorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->guardian !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['required', 'in:M,F'],
            'care_grade' => ['required', 'integer', 'between:0,5'],
            'care_grade_no' => ['nullable', 'string', 'max:30'],
            'diseases' => ['nullable', 'array'],
            'diseases.*' => ['string', 'max:50'],
            'special_notes' => ['nullable', 'string', 'max:1000'],
            'home_address' => ['required', 'string', 'max:255'],
            'home_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'home_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function messages(): array
    {
        return [
            'birth_date.before' => '생년월일은 오늘 이전이어야 합니다.',
            'care_grade.between' => '장기요양 등급은 0(등급외) ~ 5등급 사이여야 합니다.',
        ];
    }
}

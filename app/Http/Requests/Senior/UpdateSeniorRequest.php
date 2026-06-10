<?php

namespace App\Http\Requests\Senior;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSeniorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy에서 처리
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:50'],
            'care_grade' => ['sometimes', 'integer', 'between:0,5'],
            'care_grade_no' => ['nullable', 'string', 'max:30'],
            'diseases' => ['nullable', 'array'],
            'special_notes' => ['nullable', 'string', 'max:1000'],
            'home_address' => ['sometimes', 'string', 'max:255'],
            'home_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'home_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}

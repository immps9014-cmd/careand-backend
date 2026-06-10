<?php

namespace App\Http\Requests\Match;

use Illuminate\Foundation\Http\FormRequest;

class StoreMatchRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->guardian !== null;
    }

    public function rules(): array
    {
        return [
            'senior_id' => ['required', 'exists:seniors,id'],
            'category_id' => ['required', 'exists:service_categories,id'],
            'mode' => ['required', 'in:normal,emergency,recurring'],
            'scheduled_start' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after:now'],
            'duration_min' => ['required', 'integer', 'between:60,720'],
            'recurrence_rule' => ['nullable', 'array', 'required_if:mode,recurring'],
            'special_request' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'scheduled_start.after' => '시작 시간은 현재 이후여야 합니다.',
            'duration_min.between' => '소요 시간은 1시간(60분) ~ 12시간(720분) 사이여야 합니다.',
        ];
    }
}

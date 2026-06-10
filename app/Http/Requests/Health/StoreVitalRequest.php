<?php

namespace App\Http\Requests\Health;

use Illuminate\Foundation\Http\FormRequest;

class StoreVitalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_id' => ['nullable', 'exists:care_sessions,id'],
            'blood_pressure_sys' => ['nullable', 'integer', 'between:60,250'],
            'blood_pressure_dia' => ['nullable', 'integer', 'between:30,180'],
            'blood_sugar' => ['nullable', 'integer', 'between:30,600'],
            'body_temperature' => ['nullable', 'numeric', 'between:33,42'],
            'heart_rate' => ['nullable', 'integer', 'between:30,250'],
            'weight' => ['nullable', 'numeric', 'between:20,200'],
            'measured_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'blood_pressure_sys.between' => '수축기 혈압은 60~250 사이여야 합니다.',
            'blood_sugar.between' => '혈당은 30~600 mg/dL 사이여야 합니다.',
            'body_temperature.between' => '체온은 33~42°C 사이여야 합니다.',
        ];
    }
}

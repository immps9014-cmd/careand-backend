<?php

namespace App\Domains\Postpartum\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NewbornStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name'                  => ['required', 'string', 'max:50'],
            'gender'                => ['required', Rule::in(['M', 'F'])],
            'birth_datetime'        => ['required', 'date'],
            'birth_weight_g'        => ['required', 'integer', 'min:500', 'max:7000'],
            'birth_height_cm'       => ['nullable', 'numeric', 'min:30', 'max:60'],
            'gestational_age_weeks' => ['nullable', 'integer', 'min:20', 'max:45'],
            'gestational_age_days'  => ['nullable', 'integer', 'min:0', 'max:6'],
            'birth_order'           => ['nullable', 'integer', 'min:1', 'max:5'],
            'apgar_1min'            => ['nullable', 'integer', 'min:0', 'max:10'],
            'apgar_5min'            => ['nullable', 'integer', 'min:0', 'max:10'],
            'nicu_days'             => ['nullable', 'integer', 'min:0'],
            'special_conditions'    => ['nullable', 'array'],
        ];
    }
}

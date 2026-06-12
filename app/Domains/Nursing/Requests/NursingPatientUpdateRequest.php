<?php

namespace App\Domains\Nursing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NursingPatientUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->guardian !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:50'],
            'birth_date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'gender' => ['sometimes', 'in:M,F'],
            'hospital_name' => ['sometimes', 'string', 'max:100'],
            'hospital_address' => ['sometimes', 'string', 'max:255'],
            'hospital_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'hospital_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'ward_room' => ['nullable', 'string', 'max:50'],
            'mobility' => ['sometimes', 'in:independent,assisted,bedridden'],
            'diseases' => ['nullable', 'array'],
            'diseases.*' => ['string', 'max:50'],
            'care_requirements' => ['nullable', 'array'],
            'special_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

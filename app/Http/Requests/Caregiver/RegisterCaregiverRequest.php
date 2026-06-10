<?php

namespace App\Http\Requests\Caregiver;

use Illuminate\Foundation\Http\FormRequest;

class RegisterCaregiverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->role === 'caregiver';
    }

    public function rules(): array
    {
        return [
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:-18 years'],
            'gender' => ['required', 'in:M,F'],
            'license_no' => ['required', 'string', 'max:30', 'unique:caregivers,license_no'],
            'license_issued_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'specialties' => ['nullable', 'array'],
            'specialties.*' => ['string', 'max:50'],
            'base_address' => ['required', 'string', 'max:255'],
            'base_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'base_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'org_id' => ['nullable', 'exists:organizations,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'birth_date.before' => '인력은 만 18세 이상이어야 합니다.',
            'license_no.unique' => '이미 등록된 자격번호입니다.',
        ];
    }
}

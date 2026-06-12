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
            'license_no' => ['nullable', 'string', 'max:30', 'unique:caregivers,license_no'],
            'license_issued_at' => ['nullable', 'required_with:license_no', 'date_format:Y-m-d', 'before_or_equal:today'],
            'service_domains' => ['nullable', 'array'],
            'service_domains.*' => ['in:senior,postpartum,nursing,housekeeping'],
            'specialties' => ['nullable', 'array'],
            'specialties.*' => ['string', 'max:50'],
            'base_address' => ['required', 'string', 'max:255'],
            'base_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'base_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'org_id' => ['nullable', 'exists:organizations,id'],
        ];
    }

    public function withValidator($validator): void
    {
        // 시니어/산후/간병 도메인은 자격증 필수 — 가사만 선택 (Phase 3 오픈 대비)
        $validator->after(function ($v) {
            $domains = $this->input('service_domains') ?: ['senior'];
            if (array_intersect($domains, ['senior', 'postpartum', 'nursing']) && !$this->filled('license_no')) {
                $v->errors()->add('license_no', '선택한 활동 도메인에는 자격번호가 필요합니다.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'birth_date.before' => '인력은 만 18세 이상이어야 합니다.',
            'license_no.unique' => '이미 등록된 자격번호입니다.',
        ];
    }
}

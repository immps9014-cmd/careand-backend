<?php

namespace App\Http\Requests\Caregiver;

use App\Support\ServiceDomains;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'license_type' => ['nullable', 'string', 'max:40'],
            'license_issued_at' => ['nullable', 'required_with:license_no', 'date_format:Y-m-d', 'before_or_equal:today'],
            'service_domains' => ['nullable', 'array'],
            // 활동 도메인 토큰은 레지스트리(SSOT)에서 도출 — 도메인 오픈 시 자동 반영(childcare/mental_care 포함).
            'service_domains.*' => ['string', Rule::in(array_keys(config('service_domains', [])))],
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
        // 자격 정책은 도메인 레지스트리(config/service_domains.php qualification)가 SSOT.
        $validator->after(function ($v) {
            $domains = $this->input('service_domains') ?: ['senior'];

            // 1) 자격번호 필수 도메인을 선택했는데 license_no 미제출 → 거부
            $requiresLicense = array_filter($domains, fn ($d) => ServiceDomains::requiresLicense($d));
            if ($requiresLicense && !$this->filled('license_no')) {
                $labels = implode('·', array_map(fn ($d) => ServiceDomains::label($d), $requiresLicense));
                $v->errors()->add('license_no', "선택한 활동 도메인({$labels})에는 자격번호가 필요합니다.");
            }

            // 2) 자격증 종류(license_type) 제한이 있는 도메인 — 제출한 종류가 허용 목록에 있어야 함.
            //    상담(mental_care)처럼 자격이 다종인 도메인의 검증 보조. (license_no 제출 시에만 검사)
            if ($this->filled('license_no')) {
                foreach ($domains as $d) {
                    $accepted = ServiceDomains::acceptedTypes($d);
                    if (empty($accepted)) {
                        continue; // 종류 제한 없음
                    }
                    $type = $this->input('license_type');

                    // 자격 필수 도메인은 종류도 필수, 그 외(권장)는 제출했을 때만 검사
                    if (ServiceDomains::requiresLicense($d) && !$type) {
                        $v->errors()->add('license_type', ServiceDomains::label($d).' 자격증 종류를 선택해 주세요.');
                        continue;
                    }
                    if ($type && !in_array($type, $accepted, true)) {
                        $v->errors()->add('license_type', ServiceDomains::label($d).' 인정 자격증이 아닙니다 (허용: '.implode(', ', $accepted).').');
                    }
                }
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

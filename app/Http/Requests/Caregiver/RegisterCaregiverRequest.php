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
        // 복수 도메인 선택 지원: 자격은 union 규칙(선택 도메인 중 하나라도 인정하면 통과)으로 검증.
        $validator->after(function ($v) {
            $domains = $this->input('service_domains') ?: ['senior'];
            $type = $this->input('license_type');

            // 1) 선택 도메인 중 하나라도 자격번호 필수면 license_no 제출 요구
            $requiresLicense = array_filter($domains, fn ($d) => ServiceDomains::requiresLicense($d));
            if ($requiresLicense && !$this->filled('license_no')) {
                $labels = implode('·', array_map(fn ($d) => ServiceDomains::label($d), $requiresLicense));
                $v->errors()->add('license_no', "선택한 활동 도메인({$labels})에는 자격번호가 필요합니다.");
            }

            // 2) 자격증 종류: 선택 도메인들의 인정 자격(accepted_types) union 으로 검증.
            //    - 자격 필수 도메인 중 종류 제한이 있는 게 있으면 license_type 필수.
            //    - 제출한 종류는 union 중 하나면 통과(복수 직군 보유자 — 단일 자격으로 대표 등록).
            if ($this->filled('license_no')) {
                $union = [];
                $needsType = false;
                foreach ($domains as $d) {
                    $accepted = ServiceDomains::acceptedTypes($d);
                    if ($accepted) {
                        $union = array_merge($union, $accepted);
                        if (ServiceDomains::requiresLicense($d)) {
                            $needsType = true;
                        }
                    }
                }
                $union = array_values(array_unique($union));

                if ($needsType && !$type) {
                    $v->errors()->add('license_type', '자격증 종류를 선택해 주세요.');
                } elseif ($type && $union && !in_array($type, $union, true)) {
                    $v->errors()->add('license_type', '선택한 활동 도메인의 인정 자격증이 아닙니다 (허용: '.implode(', ', $union).').');
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

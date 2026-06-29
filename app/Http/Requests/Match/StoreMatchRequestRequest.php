<?php

namespace App\Http\Requests\Match;

use App\Domains\Housekeeping\Models\ServiceAddress;
use App\Domains\Nursing\Models\NursingPatient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

class StoreMatchRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->guardian !== null;
    }

    /**
     * service_domain 미전송(기존 클라이언트) = senior — 100% 하위호환.
     */
    protected function prepareForValidation(): void
    {
        if (!$this->filled('service_domain')) {
            $this->merge(['service_domain' => 'senior']);
        }
    }

    public function rules(): array
    {
        $domain = $this->input('service_domain');

        return [
            'service_domain' => ['required', 'in:senior,nursing,living_support'],
            'senior_id' => ['required_if:service_domain,senior', 'nullable', 'exists:seniors,id'],
            'nursing_patient_id' => ['required_if:service_domain,nursing', 'nullable', 'exists:nursing_patients,id'],
            'service_address_id' => ['required_if:service_domain,living_support', 'nullable', 'exists:service_addresses,id'],
            'category_id' => ['required', 'exists:service_categories,id,is_active,1'],
            'mode' => ['required', 'in:normal,emergency,recurring'],
            'scheduled_start' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after:now'],
            // 간병은 24시간 상주(1440분)까지 허용
            'duration_min' => ['required', 'integer', $domain === 'nursing' ? 'between:60,1440' : 'between:60,720'],
            'recurrence_rule' => ['nullable', 'array', 'required_if:mode,recurring'],
            'recurrence_rule.days' => ['nullable', 'integer', 'between:1,30'],
            'special_request' => ['nullable', 'string', 'max:1000'],
            'requirements' => ['nullable', 'array'],
            // 선호 돌봄전문가 성별 (M/F). 미지정=무관 — 매칭에서 소프트 가산 신호로만 사용
            'requirements.preferred_gender' => ['nullable', 'in:M,F'],
            // 보호자 희망 상한 시급(선택). 가격 레이어 산출/역경매 가드레일에 사용
            'budget_hourly' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            // 카테고리-도메인 정합
            if ($this->filled('category_id')) {
                $catDomain = DB::table('service_categories')
                    ->where('id', $this->input('category_id'))
                    ->value('domain');
                if ($catDomain !== null && $catDomain !== $this->input('service_domain')) {
                    $v->errors()->add('category_id', '선택한 카테고리가 서비스 도메인과 일치하지 않습니다.');
                }
            }

            // 간병 환자 소유권
            if ($this->input('service_domain') === 'nursing' && $this->filled('nursing_patient_id')) {
                $owned = NursingPatient::where('id', $this->input('nursing_patient_id'))
                    ->where('guardian_id', $this->user()->guardian?->id)
                    ->exists();
                if (!$owned) {
                    $v->errors()->add('nursing_patient_id', '본인이 등록한 환자만 매칭 요청할 수 있습니다.');
                }
            }

            // 가사 주소 소유권
            if ($this->input('service_domain') === 'living_support' && $this->filled('service_address_id')) {
                $owned = ServiceAddress::where('id', $this->input('service_address_id'))
                    ->where('guardian_id', $this->user()->guardian?->id)
                    ->exists();
                if (!$owned) {
                    $v->errors()->add('service_address_id', '본인이 등록한 주소만 매칭 요청할 수 있습니다.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'scheduled_start.after' => '시작 시간은 현재 이후여야 합니다.',
            'duration_min.between' => '소요 시간이 허용 범위를 벗어났습니다.',
            'senior_id.required_if' => '시니어 돌봄 요청에는 어르신 선택이 필요합니다.',
            'nursing_patient_id.required_if' => '간병 요청에는 환자 선택이 필요합니다.',
        ];
    }
}

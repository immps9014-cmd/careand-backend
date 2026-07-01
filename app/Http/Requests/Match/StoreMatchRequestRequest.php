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
            'service_domain' => ['required', 'in:senior,nursing,living_support,postpartum,childcare,mental_care'],
            'senior_id' => ['required_if:service_domain,senior', 'nullable', 'exists:seniors,id'],
            'nursing_patient_id' => ['required_if:service_domain,nursing', 'nullable', 'exists:nursing_patients,id'],
            'service_address_id' => ['required_if:service_domain,living_support', 'nullable', 'exists:service_addresses,id'],
            'postpartum_client_id' => ['required_if:service_domain,postpartum', 'nullable', 'exists:postpartum_clients,id'],
            'childcare_child_id' => ['required_if:service_domain,childcare', 'nullable', 'exists:children,id'],
            'mental_care_client_id' => ['required_if:service_domain,mental_care', 'nullable', 'exists:mental_care_clients,id'],
            'category_id' => ['required', 'exists:service_categories,id,is_active,1'],
            'mode' => ['required', 'in:normal,emergency,recurring'],
            'scheduled_start' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after:now'],
            // 간병은 24시간 상주(1440분)까지 허용
            'duration_min' => ['required', 'integer', $domain === 'nursing' ? 'between:60,1440' : 'between:60,720'],
            'recurrence_rule' => ['nullable', 'array', 'required_if:mode,recurring'],
            'recurrence_rule.days' => ['nullable', 'integer', 'between:1,30'],
            // 요일 반복(ISO 1=월..7=일) + 반복 주수. weekdays 지정 시 해당 요일마다 회차 생성.
            'recurrence_rule.weekdays' => ['nullable', 'array'],
            'recurrence_rule.weekdays.*' => ['integer', 'between:1,7'],
            'recurrence_rule.weeks' => ['nullable', 'integer', 'between:1,12'],
            'special_request' => ['nullable', 'string', 'max:1000'],
            'requirements' => ['nullable', 'array'],
            // 동행(LS_COMPANION) 경로 — 만남=service_address, 방문/복귀/경유지 + 이동수단. (P2-2)
            'requirements.companion_route' => ['nullable', 'array'],
            'requirements.companion_route.destination' => ['nullable', 'string', 'max:255'],
            'requirements.companion_route.return_to_origin' => ['nullable', 'boolean'],
            'requirements.companion_route.return_address' => ['nullable', 'string', 'max:255'],
            'requirements.companion_route.waypoints' => ['nullable', 'array', 'max:5'],
            'requirements.companion_route.waypoints.*' => ['string', 'max:255'],
            'requirements.companion_route.transport' => ['nullable', 'in:taxi,transit'],
            // 선호 돌봄전문가 성별 (M/F). 미지정=무관 — 매칭에서 소프트 가산 신호로만 사용
            'requirements.preferred_gender' => ['nullable', 'in:M,F'],
            // 직접 지정(찜한 전문가 등) — 해당 전문가를 최상단 직접 후보로 초대
            'requirements.preferred_caregiver_id' => ['nullable', 'integer', 'exists:caregivers,id'],
            // 보호자 희망 상한 시급(선택). 가격 레이어 산출/역경매 가드레일에 사용
            'budget_hourly' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            // 정기 요청은 연속 일수(days) 또는 반복 요일(weekdays) 중 하나는 지정돼야 함
            if ($this->input('mode') === 'recurring') {
                $rule = (array) $this->input('recurrence_rule', []);
                $hasDays = (int) ($rule['days'] ?? 0) >= 1;
                $hasWeekdays = !empty(array_filter((array) ($rule['weekdays'] ?? [])));
                if (!$hasDays && !$hasWeekdays) {
                    $v->errors()->add('recurrence_rule', '정기 요청은 연속 일수 또는 반복 요일을 지정해야 합니다.');
                }
            }

            // 카테고리-도메인 정합
            $catCode = null;
            if ($this->filled('category_id')) {
                $cat = DB::table('service_categories')
                    ->where('id', $this->input('category_id'))
                    ->first(['domain', 'code']);
                if ($cat && $cat->domain !== null && $cat->domain !== $this->input('service_domain')) {
                    $v->errors()->add('category_id', '선택한 카테고리가 서비스 도메인과 일치하지 않습니다.');
                }
                $catCode = $cat->code ?? null;
            }

            // 동행(LS_COMPANION)은 방문 장소·이동수단 필수, 복귀 미동일 시 복귀 장소 필수. (P2-2)
            if ($catCode === 'LS_COMPANION') {
                $route = (array) data_get($this->input('requirements'), 'companion_route', []);
                if (empty($route['destination'])) {
                    $v->errors()->add('requirements.companion_route.destination', '동행 방문 장소를 입력해 주세요.');
                }
                if (empty($route['transport'])) {
                    $v->errors()->add('requirements.companion_route.transport', '이동 수단을 선택해 주세요.');
                }
                $returnToOrigin = filter_var($route['return_to_origin'] ?? true, FILTER_VALIDATE_BOOLEAN);
                if (!$returnToOrigin && empty($route['return_address'])) {
                    $v->errors()->add('requirements.companion_route.return_address', '복귀 장소를 입력해 주세요.');
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

            // 산모 소유권 (postpartum_clients는 guardian_id가 아니라 user_id 기준)
            if ($this->input('service_domain') === 'postpartum' && $this->filled('postpartum_client_id')) {
                $owned = \App\Domains\Postpartum\Models\PostpartumClient::where('id', $this->input('postpartum_client_id'))
                    ->where('user_id', $this->user()->id)
                    ->exists();
                if (!$owned) {
                    $v->errors()->add('postpartum_client_id', '본인이 등록한 산모만 매칭 요청할 수 있습니다.');
                }
            }

            // 아동 소유권 (children은 guardian_id 기준)
            if ($this->input('service_domain') === 'childcare' && $this->filled('childcare_child_id')) {
                $owned = \App\Models\Child::where('id', $this->input('childcare_child_id'))
                    ->where('guardian_id', $this->user()->guardian?->id)
                    ->exists();
                if (!$owned) {
                    $v->errors()->add('childcare_child_id', '본인이 등록한 아동만 매칭 요청할 수 있습니다.');
                }
            }

            // 마음돌봄 대상 소유권 (mental_care_clients는 guardian_id 기준)
            if ($this->input('service_domain') === 'mental_care' && $this->filled('mental_care_client_id')) {
                $owned = \App\Models\MentalCareClient::where('id', $this->input('mental_care_client_id'))
                    ->where('guardian_id', $this->user()->guardian?->id)
                    ->exists();
                if (!$owned) {
                    $v->errors()->add('mental_care_client_id', '본인이 등록한 대상만 매칭 요청할 수 있습니다.');
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
            'postpartum_client_id.required_if' => '산후관리 요청에는 산모 선택이 필요합니다.',
            'childcare_child_id.required_if' => '아이돌봄 요청에는 아동 선택이 필요합니다.',
            'mental_care_client_id.required_if' => '마음돌봄 요청에는 대상 선택이 필요합니다.',
        ];
    }
}
